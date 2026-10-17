<?php

namespace App\Support\Garmin;

use App\Data\Garmin\GarminCsvRow;
use App\Data\Garmin\GarminImportConflict;
use App\Data\Garmin\GarminImportPlan;
use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Sorts parsed Garmin rows against the athlete's stored activities into new
 * rows, conflicts, and rows already imported. Reads only; nothing is written
 * until the visitor confirms the review. With no athlete yet, every row is new.
 */
final readonly class PlanGarminImport
{
    public function __construct(private StartTimeMatcher $startTimeMatcher) {}

    /**
     * @param  array<int, GarminCsvRow>  $rows
     */
    public function __invoke(?User $athlete, array $rows): GarminImportPlan
    {
        if ($athlete === null) {
            return new GarminImportPlan($rows, [], [], null);
        }

        $dates = array_values(array_unique(array_map(
            static fn (GarminCsvRow $row): string => $row->activityDate->toDateString(),
            $rows,
        )));

        /*
         * One query for every row, served by the (user_id, activity_date)
         * index: a conflict or an identical run can only exist on a date the
         * file contains.
         *
         * The dates are bound as CarbonImmutable on purpose: the grammar writes them as
         * "Y-m-d H:i:s", the very format the `date` cast stores activity_date in, so the
         * equality matches. Binding toDateString() here would compare "2026-10-11" with
         * "2026-10-11 00:00:00" as TEXT and match nothing. A range belongs to
         * Activity::onDaysBetween instead.
         */
        $stored = $dates === [] ? collect() : $athlete->activities()
            ->whereIn('activity_date', array_map(
                static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'UTC'),
                $dates,
            ))
            ->get()
            ->keyBy('id');

        $alreadyImported = [];
        $alreadyImportedActivities = [];

        /*
         * Identity first, for the whole file, before any conflict is claimed.
         * Otherwise an earlier row a minute away could claim the stored run as
         * its conflict, and the row identical to that run — a re-import — would
         * fall through to "new" and be inserted a second time.
         */
        foreach ($rows as $index => $row) {
            $identical = $stored->first(
                fn (Activity $activity): bool => $activity->isGarminSourced() && $row->isIdenticalTo($activity),
            );

            if ($identical !== null) {
                $alreadyImported[$index] = $row;
                $alreadyImportedActivities[$index] = $identical;
                $stored->forget($identical->id);
            }
        }

        /*
         * Closest pairs first, across the whole file: every (row, stored run)
         * pair within the tolerance is ranked by start difference, and each
         * row and each stored run is taken at most once. Claiming in file order
         * instead would let an earlier row 90 s away take a stored run that a
         * later row matches to the second — the later row would then be
         * planned as new, and a Skip would leave that run on the map twice.
         * Ties go to the earlier row, then the lower stored id.
         */
        $pairs = [];

        foreach ($rows as $index => $row) {
            if (isset($alreadyImported[$index])) {
                continue;
            }

            foreach ($stored as $activity) {
                $difference = $this->startTimeMatcher->difference($row->startDateLocal, $activity);

                if ($difference !== null) {
                    $pairs[] = [$difference, $index, $activity->id];
                }
            }
        }

        sort($pairs);

        $conflicts = [];
        $claimed = [];

        foreach ($pairs as [, $index, $activityId]) {
            if (isset($conflicts[$index]) || isset($claimed[$activityId])) {
                continue;
            }

            $conflicts[$index] = new GarminImportConflict($index, $rows[$index], $stored[$activityId]);
            $claimed[$activityId] = true;
        }

        ksort($conflicts);

        $newRows = array_diff_key($rows, $alreadyImported, $conflicts);

        /*
         * The matched stored run rides along so the apply step can fill its empty
         * metrics without a second lookup. The fingerprint still hashes only the row
         * indexes: which runs are already imported is what the visitor was shown.
         */
        return new GarminImportPlan($newRows, $conflicts, $alreadyImported, $athlete->id, $alreadyImportedActivities);
    }
}
