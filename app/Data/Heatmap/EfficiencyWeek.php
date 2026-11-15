<?php

namespace App\Data\Heatmap;

/**
 * One row of the "Weekly figures" table, the chart's text alternative: a week's dates, its
 * drawn runs, its 4-week median and last year's, each already formatted. A week with no
 * point is null and the table prints "none" for it, never 0:00.
 */
final readonly class EfficiencyWeek
{
    public function __construct(
        public string $rangeLabel,
        public int $runs,
        public ?string $median,
        public ?string $lastYear,
    ) {}
}
