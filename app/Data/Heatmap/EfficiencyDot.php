<?php

namespace App\Data\Heatmap;

use App\Enums\EfficiencyRunKind;

/**
 * One drawn run: its marker kind and where it sits over the SVG, in percent.
 *
 * The dot is HTML positioned over the chart, not an SVG circle, because a circle inside a
 * viewBox stretched with preserveAspectRatio="none" would be drawn as an ellipse.
 */
final readonly class EfficiencyDot
{
    public function __construct(
        public EfficiencyRunKind $kind,
        public string $left,
        public string $top,
    ) {}
}
