<?php

namespace App\Data\Heatmap;

use App\Enums\EfficiencyRunKind;
use Carbon\CarbonImmutable;

/**
 * One run that passed FR-023's gates, with its pace restated at the window's reference
 * heart rate. The pace stays a float: it is rounded to whole seconds once, at the edge,
 * by the card builder.
 */
final readonly class MeasuredRun
{
    public function __construct(
        public CarbonImmutable $date,
        public int $weekday,
        public int $weekPosition,
        public EfficiencyRunKind $kind,
        public float $paceAtReference,
    ) {}
}
