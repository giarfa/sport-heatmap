<?php

namespace App\Support\Heatmap;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The 112-day window every heatmap concern reads: US-003 reconciles the current
 * one, US-004 renders it, US-005 calibrates percentiles over it, and US-013 lets
 * the reader step back and forward through earlier ones.
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

        return self::endingInWeekOf($today);
    }

    /**
     * The window whose last row is the ISO week containing the given day.
     */
    public static function endingInWeekOf(CarbonInterface $day): self
    {
        $day = CarbonImmutable::instance($day);
        $windowWeeks = (int) config('heatmap.window_weeks');

        $startsOn = $day->startOfWeek()->subWeeks($windowWeeks - 1);
        $endsOn = $day->endOfWeek()->startOfDay();

        return new self($startsOn, $endsOn);
    }

    /**
     * The window a `to` query value names, or the current window when the value
     * cannot name a past or present one.
     *
     * The URL names the window's last week (its end), not its start, because
     * the end is what the reader navigates by — "the 16 weeks up to 6 Sep" —
     * and it stays meaningful if `window_weeks` ever changes, where a start
     * date would silently shift every shared link.
     *
     * An empty, malformed, impossible (`2026-02-31`), or future value falls
     * back to the current window instead of answering 404 or 422: the spec
     * requires a bad link to render the map "without an error", and a stale
     * bookmark is better served by today's map than by an error page. The
     * round-trip comparison is what rejects a date PHP would otherwise roll
     * over (`2026-02-31` becoming 3 March). A mid-week date is read as its
     * week, so a hand-typed date still lands on a whole window.
     */
    public static function fromRequestedEnd(string $requested, CarbonInterface $today): self
    {
        $current = self::current($today);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) !== 1) {
            return $current;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $requested);

        if ($day === null || $day->format('Y-m-d') !== $requested) {
            return $current;
        }

        $window = self::endingInWeekOf($day);

        return $window->endsOn->greaterThan($current->endsOn) ? $current : $window;
    }

    /**
     * The window one navigation step earlier.
     *
     * An old window is never clamped to the oldest stored run: how far back
     * Previous may go is a question for the stored data, which this value
     * object does not see, so the controller disables the control instead.
     */
    public function previous(): self
    {
        return self::endingInWeekOf($this->endsOn->subWeeks($this->navigationStepWeeks()));
    }

    /**
     * The window one navigation step later, never past the current window — a
     * future window would render nothing but future cells.
     */
    public function next(CarbonInterface $today): self
    {
        $current = self::current($today);
        $next = self::endingInWeekOf($this->endsOn->addWeeks($this->navigationStepWeeks()));

        return $next->endsOn->greaterThan($current->endsOn) ? $current : $next;
    }

    public function isCurrent(CarbonInterface $today): bool
    {
        return $this->endsOn->equalTo(self::current($today)->endsOn);
    }

    private function navigationStepWeeks(): int
    {
        return (int) config('heatmap.navigation_step_weeks');
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
