<?php

namespace App\Support\Heatmap;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * One athlete's daily running distance inside a window, summed in a single query.
 *
 * 112 days means 112 opportunities to query per day if this loops — one grouped
 * aggregate is the only shape that keeps the dashboard request cheap.
 */
final readonly class DailyRunningDistance
{
    /**
     * @return array<string, int> keyed by "Y-m-d" => summed distance in metres
     */
    public function __invoke(User $athlete, HeatmapWindow $window): array
    {
        $rows = $athlete->activities()
            ->selectRaw('activity_date, SUM(distance_meters) as meters')
            ->onDaysBetween($window->startsOn, $window->endsOn)
            ->groupBy('activity_date')
            ->get();

        $meters = [];

        foreach ($rows as $row) {
            $date = $row->getAttribute('activity_date');
            $date = $date instanceof Carbon ? $date->toDateString() : (string) $date;

            $meters[$date] = (int) $row->getAttribute('meters');
        }

        return $meters;
    }
}
