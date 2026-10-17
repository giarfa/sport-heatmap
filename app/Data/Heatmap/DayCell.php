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
    /**
     * @param  array<int, DayRun>  $runs  The day's runs in start order; only a Ran cell carries any
     */
    public function __construct(
        public CarbonImmutable $date,
        public int $distanceMeters,
        public DayCellState $state,
        public int $level,
        public array $runs = [],
    ) {}

    /**
     * Whether the day opens a detail: it ran, and there is a run to list. Rest and
     * future days never do.
     */
    public function hasDetail(): bool
    {
        return $this->state === DayCellState::Ran && $this->runs !== [];
    }

    /**
     * The screen-reader name of the button that opens the detail: the plain name plus
     * the run count and the action, "Saturday 3 Oct 2026: 13.6 km, 2 runs. Show details".
     */
    public function detailButtonName(): string
    {
        $count = count($this->runs);

        return "{$this->accessibleName()}, {$count} ".($count === 1 ? 'run' : 'runs').'. Show details';
    }

    /**
     * The detail's heading: "Sat 3 Oct 2026".
     */
    public function detailTitle(): string
    {
        return $this->date->format('D j M Y');
    }

    /**
     * The detail's sub-line: "2 runs · 13.6 km".
     */
    public function detailSummary(): string
    {
        $count = count($this->runs);

        return $count.' '.($count === 1 ? 'run' : 'runs')." · {$this->kilometres()} km";
    }

    /**
     * The Blade cell-class modifier: 'rest', 'future', or 'l1'..'l4'. Future
     * cells always return 'future' — a day that has not happened cannot
     * carry an intensity, regardless of the level it was constructed with.
     */
    public function modifier(): string
    {
        return match ($this->state) {
            DayCellState::Future => 'future',
            DayCellState::Rest => 'rest',
            DayCellState::Ran => 'l'.$this->level,
        };
    }

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
