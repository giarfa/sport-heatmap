<?php

namespace App\Data\Heatmap;

use App\Data\ImportStatus;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Where the reader is in the history and which way they can go: the shown window's
 * heading, and the Previous and Next links (null when a control is disabled).
 */
final readonly class WindowNavigation
{
    public function __construct(
        public bool $isCurrent,
        public CarbonImmutable $endsOn,
        public ?string $previousUrl,
        public ?string $nextUrl,
    ) {}

    /**
     * Previous is available only while the shown window starts after the oldest stored
     * run — with no run stored there is nothing older to show. Next is never available on
     * the current window, and lands on the plain home URL when it reaches it, so the
     * current window has one address.
     */
    public static function for(HeatmapWindow $window, CarbonInterface $today, ?CarbonInterface $oldestRun): self
    {
        $isCurrent = $window->isCurrent($today);

        $previousUrl = $oldestRun !== null && $oldestRun->lessThan($window->startsOn)
            ? self::urlFor($window->previous())
            : null;

        $next = $window->next($today);
        $nextUrl = $isCurrent
            ? null
            : ($next->isCurrent($today) ? route('dashboard') : self::urlFor($next));

        return new self($isCurrent, $window->endsOn, $previousUrl, $nextUrl);
    }

    public function heading(): string
    {
        return $this->isCurrent ? 'Last 16 weeks' : '16 weeks to '.$this->endsOn->format('j M');
    }

    /**
     * The current window keeps the title it always had; an earlier one says which it is, so
     * browser history and tabs tell the windows apart.
     */
    public function pageTitle(): string
    {
        return ($this->isCurrent ? 'Dashboard' : $this->heading()).' — Sport Heatmap';
    }

    /**
     * The line above an empty grid. The current window explains how the map fills in (the
     * import status says how the runs arrive); an earlier window only says that
     * nothing is stored there, since no import will add to it.
     */
    public function emptyWindowNote(HeatmapGrid $grid, ImportStatus $status): ?string
    {
        if ($this->isCurrent) {
            return $status->runsInWindow === 0 ? $status->emptyWindowNote() : null;
        }

        return $grid->hasRuns() ? null : 'No runs stored in these 16 weeks.';
    }

    private static function urlFor(HeatmapWindow $window): string
    {
        return route('dashboard', ['to' => $window->endsOn->toDateString()]);
    }
}
