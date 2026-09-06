<?php

namespace App\Data\Heatmap;

/**
 * One line of the intensity legend: a level, its name, and the kilometre
 * range it stands for.
 */
final readonly class LegendRow
{
    public function __construct(
        public int $level,
        public string $name,
        public string $rangeLabel,
    ) {}
}
