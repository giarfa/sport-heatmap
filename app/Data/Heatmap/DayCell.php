<?php

namespace App\Data\Heatmap;

use App\Enums\DayCellState;
use Carbon\CarbonImmutable;

/**
 * One day of the heatmap grid, carrying its own formatting and accessible name so
 * no arithmetic or string-building happens in Blade.
 */
final readonly class DayCell
{
    public function __construct(
        public CarbonImmutable $date,
        public int $distanceMeters,
        public DayCellState $state,
    ) {}

    /**
     * The distance in kilometres, to one decimal.
     */
    public function kilometres(): string
    {
        return number_format($this->distanceMeters / 1000, 1);
    }

    /**
     * The screen-reader name: full date plus the exact distance, or the rest-day
     * or not-yet-happened phrasing the mockup fixes.
     */
    public function accessibleName(): string
    {
        $date = $this->date->format('l j M Y');

        return match ($this->state) {
            DayCellState::Ran => "{$date}: {$this->kilometres()} km",
            DayCellState::Rest => "{$date}: rest day, 0 km",
            DayCellState::Future => "{$date}: not yet",
        };
    }

    /**
     * The pointer-hover title for ran and rest days; future cells carry none.
     */
    public function pointerTitle(): ?string
    {
        $date = $this->date->format('l j M Y');

        return match ($this->state) {
            DayCellState::Ran => "{$date} — {$this->kilometres()} km",
            DayCellState::Rest => "{$date} — rest",
            DayCellState::Future => null,
        };
    }
}
