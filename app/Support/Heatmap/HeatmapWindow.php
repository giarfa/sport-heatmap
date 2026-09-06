<?php

namespace App\Support\Heatmap;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single 112-day window every heatmap concern reads: US-003 reconciles it,
 * US-004 renders it, US-005 calibrates percentiles over it.
 */
final readonly class HeatmapWindow
{
    public function __construct(
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
    ) {}

    /**
     * The window anchored to the current, in-progress ISO week.
     *
     * The window is anchored to the ISO week rather than to "today minus 112
     * days", because the grid's bottom row must always be the current,
     * in-progress week — a rolling day-count anchor would make that row's start
     * drift across the day of the week the sync happens to run on.
     */
    public static function current(?CarbonInterface $today = null): self
    {
        $today = $today !== null ? CarbonImmutable::instance($today) : CarbonImmutable::now();
        $windowWeeks = (int) config('heatmap.window_weeks');

        $startsOn = $today->startOfWeek()->subWeeks($windowWeeks - 1);
        $endsOn = $today->endOfWeek()->startOfDay();

        return new self($startsOn, $endsOn);
    }

    /**
     * Inclusive on both ends, compared as dates — time of day never matters here.
     */
    public function contains(CarbonInterface $date): bool
    {
        $date = CarbonImmutable::instance($date)->startOfDay();

        return $date->greaterThanOrEqualTo($this->startsOn) && $date->lessThanOrEqualTo($this->endsOn);
    }

    /**
     * The window start as a UTC epoch, for the Strava `after` query parameter.
     */
    public function afterTimestamp(): int
    {
        return $this->startsOn->getTimestamp();
    }

    /**
     * The number of calendar days the window spans — asserts the 112 the spec
     * names, and gives US-004 its row count source.
     */
    public function days(): int
    {
        return (int) $this->startsOn->diffInDays($this->endsOn) + 1;
    }
}
