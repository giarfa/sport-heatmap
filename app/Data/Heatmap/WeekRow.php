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

    /**
     * The week's Monday-to-Sunday range: "18 – 24 May", "29 Jun – 5 Jul", and with the
     * year "29 Dec 2025 – 4 Jan 2026" when it crosses one. Shared by the weekly trend and
     * the aerobic-efficiency card so both name a week the same way.
     */
    public function rangeLabel(bool $withYear = false): string
    {
        $weekEnd = $this->weekStart->addDays(6);

        if ($this->weekStart->year !== $weekEnd->year) {
            return $withYear
                ? $this->weekStart->format('j M Y').' – '.$weekEnd->format('j M Y')
                : $this->weekStart->format('j M').' – '.$weekEnd->format('j M');
        }

        $range = $this->weekStart->month === $weekEnd->month
            ? $this->weekStart->format('j').' – '.$weekEnd->format('j M')
            : $this->weekStart->format('j M').' – '.$weekEnd->format('j M');

        return $withYear ? $range.' '.$weekEnd->format('Y') : $range;
    }
}
