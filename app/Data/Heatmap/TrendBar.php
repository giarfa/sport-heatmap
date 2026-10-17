<?php

namespace App\Data\Heatmap;

/**
 * One week of the weekly trend: the bar's geometry, its 4-week average, and the
 * strings the chart and its text alternative print for it.
 *
 * Coordinates are strings of at most two decimals in the chart's 160 x 100 space,
 * so the view prints them without arithmetic.
 */
final readonly class TrendBar
{
    public function __construct(
        public string $rangeLabel,
        public string $accessibleName,
        public string $kilometres,
        public ?string $averageKilometres,
        public string $x,
        public string $xEnd,
        public string $y,
        public string $height,
        public string $heightPercent,
        public bool $isPartial,
        public bool $isZero,
    ) {}
}
