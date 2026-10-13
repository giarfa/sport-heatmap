<?php

namespace App\Support\Heatmap;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The three weeks of running distance just before a window, summed in a single query.
 *
 * The first points of the 4-week rolling average reach back into weeks the window does
 * not show; this is where those weeks come from.
 */
final readonly class LeadInDistance
{
    private const LEAD_IN_WEEKS = 3;

    /**
     * Kept apart from DailyRunningDistance on purpose. The intensity scale is calibrated
     * over the daily totals of the shown window only (FR-006), so widening that query by
     * 21 days would let weeks the reader cannot see move the legend and every cell's level.
     * One more grouped aggregate is the price of keeping the two concerns apart.
     *
     * @return array<int, int> three weekly totals in metres, oldest week first; zero for a week with no run
     */
    public function __invoke(User $athlete, HeatmapWindow $window): array
    {
        $leadInStart = $window->startsOn->subWeeks(self::LEAD_IN_WEEKS);

        /*
         * The upper bound is exclusive (`< startsOn`) rather than `BETWEEN … AND startsOn - 1 day`.
         * SQLite stores the date column as "2026-05-17 00:00:00", which sorts after the plain
         * "2026-05-17" a BETWEEN bound would compare it with, so a run on the lead-in's last
         * day (the Sunday before the window) would silently drop out of the average.
         */
        $rows = $athlete->activities()
            ->selectRaw('activity_date, SUM(distance_meters) as meters')
            ->where('activity_date', '>=', $leadInStart->toDateString())
            ->where('activity_date', '<', $window->startsOn->toDateString())
            ->groupBy('activity_date')
            ->get();

        $weeklyMeters = array_fill(0, self::LEAD_IN_WEEKS, 0);

        foreach ($rows as $row) {
            $date = $row->getAttribute('activity_date');
            $date = CarbonImmutable::parse($date instanceof Carbon ? $date->toDateString() : (string) $date)->startOfDay();

            $weekIndex = intdiv((int) $leadInStart->diffInDays($date), 7);

            $weeklyMeters[$weekIndex] += (int) $row->getAttribute('meters');
        }

        return $weeklyMeters;
    }
}
