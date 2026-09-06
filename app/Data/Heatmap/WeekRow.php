<?php

namespace App\Data\Heatmap;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One row of the heatmap: a week's seven day cells plus its total.
 */
final readonly class WeekRow
{
    /**
     * @param  array<int, DayCell>  $days
     */
    public function __construct(
        public CarbonImmutable $weekStart,
        public array $days,
        public int $totalMeters,
        public bool $isPartial,
        public bool $isCurrent,
    ) {
        if (count($this->days) !== 7) {
            throw new InvalidArgumentException('A WeekRow must carry exactly seven days, got '.count($this->days).'.');
        }
    }

    /**
     * The week's total distance in kilometres, to one decimal.
     */
    public function kilometres(): string
    {
        return number_format($this->totalMeters / 1000, 1);
    }

    /**
     * The short label for the week-start header cell, e.g. "18 May".
     */
    public function weekLabel(): string
    {
        return $this->weekStart->format('j M');
    }
}
