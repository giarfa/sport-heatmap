<?php

namespace App\Data\Heatmap;

use App\Enums\DayCellState;
use Carbon\CarbonImmutable;

/**
 * The assembled 16-week heatmap: every WeekRow the dashboard renders, plus the
 * window's own bounds for the page subtitle.
 */
final readonly class HeatmapGrid
{
    /**
     * @param  array<int, WeekRow>  $weeks
     */
    public function __construct(
        public array $weeks,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public IntensityScale $scale,
    ) {}

    /**
     * The window's date range, e.g. "18 May – 6 Sep 2026".
     */
    public function label(): string
    {
        return $this->startsOn->format('j M').' – '.$this->endsOn->format('j M Y');
    }

    /**
     * Whether any day of the window has a run — what tells an empty window from a quiet one.
     */
    public function hasRuns(): bool
    {
        foreach ($this->weeks as $week) {
            foreach ($week->days as $day) {
                if ($day->state === DayCellState::Ran) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every day that opens a detail, oldest first — what the day-detail component
     * renders one template for.
     *
     * @return array<int, DayCell>
     */
    public function daysWithDetail(): array
    {
        $days = [];

        foreach ($this->weeks as $week) {
            foreach ($week->days as $day) {
                if ($day->hasDetail()) {
                    $days[] = $day;
                }
            }
        }

        return $days;
    }
}
