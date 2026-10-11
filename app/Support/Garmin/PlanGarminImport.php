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
 * until the owner confirms the review.
 */
final readonly class PlanGarminImport
{
    public function __construct(private StartTimeMatcher $startTimeMatcher) {}

    /**
     * @param  array<int, GarminCsvRow>  $rows
     */
    public function __invoke(User $athlete, array $rows): GarminImportPlan
    {
        $dates = array_values(array_unique(array_map(
            static fn (GarminCsvRow $row): string => $row->activityDate->toDateString(),
            $rows,
        )));

        /*
         * One query for every row, served by the (user_id, activity_date)
         * index: a conflict or an identical run can only exist on a date the
         * file contains.
         */
        $stored = $dates === [] ? collect() : $athlete->activities()
            ->whereIn('activity_date', array_map(
                static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'UTC'),
                $dates,
            ))
            ->get()
            ->keyBy('id');

        $alreadyImported = [];

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
                $stored->forget($identical->id);
            }
        }

        $newRows = [];
        $conflicts = [];

        /*
         * Each stored run answers at most one CSV row, the first in file order
         * that finds it closest. Without claiming, two CSV runs a minute apart
         * could both overwrite the same stored run, and one of them would be
         * lost.
         */
        foreach ($rows as $index => $row) {
            if (isset($alreadyImported[$index])) {
                continue;
            }

            $match = $this->startTimeMatcher->closest($row->startDateLocal, $stored);

            if ($match === null) {
                $newRows[$index] = $row;

                continue;
            }

            $conflicts[$index] = new GarminImportConflict($index, $row, $match);
            $stored->forget($match->id);
        }

        return new GarminImportPlan($newRows, $conflicts, $alreadyImported);
    }
}
