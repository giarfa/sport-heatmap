<?php

namespace App\Data\Heatmap;

use App\Enums\AerobicEfficiencyState;

/**
 * The aerobic-efficiency card for one window: its state and every string, tick, dot and
 * polyline it prints.
 *
 * Built from the same WeekRows as the grid and carrying no model, request or view, so it
 * caches with the grid and Blade does no arithmetic over it.
 */
final readonly class AerobicEfficiency
{
    /**
     * @param  string  $message  the sentence of the Empty, NotEnoughRuns and NoMedian states, else ''
     * @param  string  $headlinePace  the latest 4-week median as "6:29", else ''
     * @param  string  $headlineAt  "at 148 bpm", else ''
     * @param  array<int, TrendTick>  $ticks  top (fastest) first; only the first carries the unit
     * @param  array<int, string>  $xLabels  the four week-start labels under the plot
     * @param  array<int, EfficiencyDot>  $dots  one per drawn run
     * @param  array<int, string>  $medianSegments  polyline `points` strings, one per unbroken run of medians
     * @param  array<int, string>  $lastYearSegments  the same for last year's line
     * @param  array<int, EfficiencyWeek>  $weeks  the weekly figures, oldest week first
     */
    public function __construct(
        public AerobicEfficiencyState $state,
        public string $message,
        public string $headlinePace,
        public string $headlineAt,
        public string $note,
        public array $ticks,
        public array $xLabels,
        public array $dots,
        public array $medianSegments,
        public array $lastYearSegments,
        public array $weeks,
        public string $title,
        public string $captionLabel,
        public bool $hasHilly,
        public bool $hasSteep,
        public bool $hasLastYear,
    ) {}

    /**
     * The state of a window with nothing to chart: a sentence and no chart.
     */
    public static function sentence(AerobicEfficiencyState $state, string $message): self
    {
        return new self($state, $message, '', '', '', [], [], [], [], [], [], '', '', false, false, false);
    }

    /**
     * Whether the card draws the chart and its weekly figures.
     */
    public function hasChart(): bool
    {
        return $this->state === AerobicEfficiencyState::Measured || $this->state === AerobicEfficiencyState::NoMedian;
    }

    public function hasHeadline(): bool
    {
        return $this->state === AerobicEfficiencyState::Measured;
    }

    public function hasMedianLine(): bool
    {
        return $this->medianSegments !== [];
    }
}
