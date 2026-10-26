<?php

namespace App\Support\Garmin;

use App\Data\Garmin\GarminCsvRow;
use App\Data\Garmin\GarminImportConflict;
use App\Data\Garmin\GarminImportPlan;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sorts parsed Garmin rows against the athlete's stored activities into new
 * rows, conflicts, and rows already imported. Reads only; nothing is written
 * until the owner confirms the review.
 */
final readonly class PlanGarminImport
{
    public function __construct(private StartTimeMatcher $startTimeMatcher) {}

    /**
     * The athlete's stored runs on the dates the rows contain, by id.
     *
     * One query for every row, served by the (user_id, activity_date) index: a
     * conflict or an identical run can only exist on a date the file contains.
     *
     * @param  array<int, GarminCsvRow>  $rows
     * @return Collection<int, Activity>
     */
    public function storedRuns(User $athlete, array $rows): Collection
    {
        $dates = array_values(array_unique(array_map(
            static fn (GarminCsvRow $row): string => $row->activityDate->toDateString(),
            $rows,
        )));

        return $dates === [] ? new Collection : $athlete->activities()
            ->onDates($dates)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<int, GarminCsvRow>  $rows
     * @param  Collection<int, Activity>|null  $stored  The runs storedRuns() returned, when the caller already loaded them
     */
    public function __invoke(User $athlete, array $rows, ?Collection $stored = null): GarminImportPlan
    {
        $stored ??= $this->storedRuns($athlete, $rows);

        /*
         * Both passes below look a row's candidates up by date instead of
         * scanning every stored run for every row. The review re-plans on each
         * render, so rows × stored runs was work a visitor could repeat by
         * reloading it. The lookups keep the order `$stored` has, which the tie
         * rules below rely on.
         */
        $garminByStartDate = [];

        foreach ($stored as $activity) {
            if ($activity->isGarminSourced()) {
                $garminByStartDate[$activity->start_date_local->format('Y-m-d')][] = $activity;
            }
        }

        $alreadyImported = [];
        $alreadyImportedActivities = [];
        $identical = [];

        /*
         * Identity first, for the whole file, before any conflict is claimed.
         * Otherwise an earlier row a minute away could claim the stored run as
         * its conflict, and the row identical to that run — a re-import — would
         * fall through to "new" and be inserted a second time.
         */
        foreach ($rows as $index => $row) {
            foreach ($garminByStartDate[$row->startDateLocal->format('Y-m-d')] ?? [] as $activity) {
                if (isset($identical[$activity->id]) || ! $row->isIdenticalTo($activity)) {
                    continue;
                }

                $alreadyImported[$index] = $row;
                $alreadyImportedActivities[$index] = $activity;
                $identical[$activity->id] = true;

                break;
            }
        }

        $unclaimedByActivityDate = [];

        foreach ($stored as $activity) {
            if (! isset($identical[$activity->id])) {
                $unclaimedByActivityDate[$activity->activity_date->format('Y-m-d')][] = $activity;
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

            foreach ($unclaimedByActivityDate[$row->startDateLocal->format('Y-m-d')] ?? [] as $activity) {
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
         * indexes: which runs are already imported is what the owner was shown.
         */
        return new GarminImportPlan($newRows, $conflicts, $alreadyImported, $athlete->id, $alreadyImportedActivities);
    }
}
