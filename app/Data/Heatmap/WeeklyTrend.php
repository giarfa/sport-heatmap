<?php

namespace App\Data\Heatmap;

/**
 * The weekly-mileage chart for one window: sixteen bars, a 4-week rolling average,
 * and every number and string the card prints.
 *
 * It is built from the same WeekRows as the grid and carries no model, request, or
 * view, so it caches with the grid and Blade does no arithmetic over it.
 */
final readonly class WeeklyTrend
{
    /**
     * @param  array<int, TrendBar>  $bars  oldest week first
     * @param  array<int, TrendTick>  $ticks  from 0 up to the top gridline
     * @param  array<int, string>  $xLabels  the four week-start labels under the plot
     * @param  array<int, string>  $lineSegments  polyline `points` strings, one per unbroken run of averages
     */
    public function __construct(
        public array $bars,
        public array $ticks,
        public array $xLabels,
        public array $lineSegments,
        public string $title,
        public string $captionLabel,
        public string $noteBefore,
        public string $noteStrong,
        public string $noteAfter,
        public bool $hasRuns,
        public bool $hasPartial,
        public bool $hasZeroWeek,
    ) {}

    /**
     * Whether the window has no run at all — the card then says so instead of drawing an empty axis.
     */
    public function isEmpty(): bool
    {
        return ! $this->hasRuns;
    }

    /**
     * The note under the heading as one plain string.
     */
    public function note(): string
    {
        return $this->noteBefore.$this->noteStrong.$this->noteAfter;
    }

    public function hasLine(): bool
    {
        return $this->lineSegments !== [];
    }
}
