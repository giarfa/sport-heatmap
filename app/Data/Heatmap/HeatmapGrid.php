<?php

namespace App\Data\Heatmap;

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
    ) {}

    /**
     * The window's date range, e.g. "18 May – 6 Sep 2026".
     */
    public function label(): string
    {
        return $this->startsOn->format('j M').' – '.$this->endsOn->format('j M Y');
    }
}
