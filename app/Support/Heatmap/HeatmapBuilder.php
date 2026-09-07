<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\DayCell;
use App\Data\Heatmap\HeatmapGrid;
use App\Data\Heatmap\IntensityScale;
use App\Data\Heatmap\WeekRow;
use App\Enums\DayCellState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Assembles the HeatmapGrid from a window and its daily totals — the one place
 * that decides which days are rest, ran, or future.
 */
final readonly class HeatmapBuilder
{
    public function __construct(
        private CalibrateIntensityScale $calibrate,
    ) {}

    /**
     * @param  array<string, int>  $dailyMeters  keyed by "Y-m-d" => summed distance in metres
     */
    public function __invoke(HeatmapWindow $window, array $dailyMeters, CarbonInterface $today): HeatmapGrid
    {
        $today = CarbonImmutable::instance($today)->startOfDay();

        /*
         * Calibrated once per grid build, never per week or per cell, so the
         * cells and the legend are bucketed by the same object and cannot
         * disagree.
         */
        $scale = ($this->calibrate)($dailyMeters);

        $weeks = [];
        $weekStart = $window->startsOn;

        while ($weekStart->lessThanOrEqualTo($window->endsOn)) {
            $weeks[] = $this->buildWeek($weekStart, $dailyMeters, $today, $scale);
            $weekStart = $weekStart->addWeek();
        }

        return new HeatmapGrid($weeks, $window->startsOn, $window->endsOn, $scale);
    }

    private function buildWeek(CarbonImmutable $weekStart, array $dailyMeters, CarbonImmutable $today, IntensityScale $scale): WeekRow
    {
        $days = [];
        $totalMeters = 0;
        $isPartial = false;

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $weekStart->addDays($offset);

            /*
             * $today is taken as an argument rather than read from the clock so
             * every date rule here is testable by naming a date, not by
             * travelling time.
             */
            if ($date->greaterThan($today)) {
                $days[] = new DayCell($date, 0, DayCellState::Future, 0);
                $isPartial = true;

                continue;
            }

            $metres = $dailyMeters[$date->toDateString()] ?? 0;
            $state = $metres > 0 ? DayCellState::Ran : DayCellState::Rest;

            $days[] = new DayCell($date, $metres, $state, $scale->levelFor($metres));
            $totalMeters += $metres;
        }

        return new WeekRow($weekStart, $days, $totalMeters, $isPartial, $isPartial);
    }
}
