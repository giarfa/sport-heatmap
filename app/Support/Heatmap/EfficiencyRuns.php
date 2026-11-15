<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\EfficiencyRun;
use App\Data\Heatmap\EfficiencySample;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The runs the aerobic-efficiency card needs, in two selective queries.
 *
 * Expected query shape, run on a grid cache miss only:
 *
 *     select activity_date, start_date_local, distance_meters, average_pace_seconds_per_km,
 *            average_heart_rate, total_ascent_meters
 *     from activities where user_id = ? and activity_date >= ? and activity_date < ?
 *     order by start_date_local
 *
 * No relation is read, so there is nothing to eager load, and the range is served by the
 * (user_id, activity_date) index.
 */
final readonly class EfficiencyRuns
{
    /** Weeks before the window that feed the first rolling medians. */
    private const LEAD_IN_WEEKS = 3;

    /** The comparison line sits exactly this many weeks back, so weekdays line up. */
    private const LAST_YEAR_WEEKS = 52;

    public function __invoke(User $athlete, HeatmapWindow $window): EfficiencySample
    {
        $firstDay = $window->startsOn->subWeeks(self::LEAD_IN_WEEKS);

        return new EfficiencySample(
            runs: $this->between($athlete, $firstDay, $window->endsOn),
            lastYearRuns: $this->between(
                $athlete,
                $firstDay->subWeeks(self::LAST_YEAR_WEEKS),
                $window->endsOn->subWeeks(self::LAST_YEAR_WEEKS),
            ),
        );
    }

    /**
     * Kept apart from DailyRunningDistance, DailyRuns and LeadInDistance on purpose. Those
     * read the shown window for the grid and its intensity scale (FR-006), and widening
     * them by three weeks, or a year back, would let weeks the reader cannot see move the
     * legend and every cell's level. Two more small indexed reads are the price of keeping
     * the concerns apart.
     *
     * @return array<int, EfficiencyRun>
     */
    private function between(User $athlete, CarbonImmutable $firstDay, CarbonImmutable $lastDay): array
    {
        /*
         * onDaysBetween() owns the comparison, so the range includes its last Sunday and
         * excludes the day before its first Monday. Never whereDate(): it wraps the column
         * in strftime() and the (user_id, activity_date) index stops serving the query.
         */
        $activities = $athlete->activities()
            ->select([
                'activity_date',
                'start_date_local',
                'distance_meters',
                'average_pace_seconds_per_km',
                'average_heart_rate',
                'total_ascent_meters',
            ])
            ->onDaysBetween($firstDay, $lastDay)
            ->orderBy('start_date_local')
            ->get();

        return $activities
            ->map(static fn ($activity): EfficiencyRun => EfficiencyRun::fromActivity($activity))
            ->all();
    }
}
