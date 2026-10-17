<?php

namespace App\Data\Heatmap;

/**
 * One y-axis step of the weekly trend: its label and where it sits, in the same
 * percentage space the SVG and the HTML labels share.
 */
final readonly class TrendTick
{
    public function __construct(
        public string $label,
        public string $bottomPercent,
        public string $y,
    ) {}
}
