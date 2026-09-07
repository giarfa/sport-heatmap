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

    /**
     * The Blade swatch-class modifier: 'rest' or 'l1'..'l4' — the same
     * contract DayCell::modifier() follows, so no arithmetic or
     * string-building for a cell class ever reaches Blade.
     */
    public function modifier(): string
    {
        return $this->level === 0 ? 'rest' : 'l'.$this->level;
    }
}
