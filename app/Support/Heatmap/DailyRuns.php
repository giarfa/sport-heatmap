<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\DayRun;
use App\Models\User;

/**
 * One athlete's individual runs inside a window, grouped by day — what the day
 * detail lists.
 */
final readonly class DailyRuns
{
    /**
     * @return array<string, array<int, DayRun>> keyed by "Y-m-d", each day's runs in start order
     */
    public function __invoke(User $athlete, HeatmapWindow $window): array
    {
        /*
         * One query for the whole window, served by the (user_id, activity_date)
         * index and run only on a grid cache miss: the detail never costs a query
         * per day, and it is built from the same rows the grid's totals are, so
         * the two cannot disagree. Only the columns DayRun reads are selected.
         */
        $activities = $athlete->activities()
            ->select([
                'activity_date',
                'start_date_local',
                'name',
                'source',
                'strava_activity_id',
                'distance_meters',
                'moving_time_seconds',
                'average_pace_seconds_per_km',
                'total_ascent_meters',
                'average_heart_rate',
            ])
            ->whereBetween('activity_date', [
                $window->startsOn->toDateString(),
                $window->endsOn->toDateString(),
            ])
            ->orderBy('start_date_local')
            ->get();

        $runs = [];

        foreach ($activities as $activity) {
            $runs[$activity->activity_date->toDateString()][] = DayRun::fromActivity($activity);
        }

        return $runs;
    }
}
