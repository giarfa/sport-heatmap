<?php

namespace App\Data;

use Carbon\CarbonInterface;

/**
 * The single fact FR-007 needs, resolved fresh on every request and never
 * cached: when the newest confirmed Garmin import changed the owner's data.
 * Every user-facing string lives here — chip, footer, detail card — so no
 * date arithmetic reaches Blade.
 */
final readonly class ImportStatus
{
    public function __construct(
        public ?CarbonInterface $lastImportedAt,
        public int $runsInWindow,
    ) {}

    public function hasImported(): bool
    {
        return $this->lastImportedAt !== null;
    }

    public function chipLabel(): string
    {
        return $this->lastImportedAt === null
            ? 'No runs imported yet'
            : "Last import {$this->lastImportedAt->diffForHumans()}";
    }

    /**
     * The lowercase variant the footer prints below 640 px, where the header
     * chip is hidden.
     */
    public function footerLabel(): string
    {
        return lcfirst($this->chipLabel());
    }

    public function detailHeading(): string
    {
        return 'Import';
    }

    /**
     * The absolute stamp the detail card prints, in the display timezone: an
     * import at 22:30 UTC is the owner's next morning, and the card must say
     * the day they remember importing on.
     */
    public function lastImportLabel(): string
    {
        return $this->lastImportedAt
            ?->copy()
            ->setTimezone((string) config('heatmap.display_timezone'))
            ->format('j M Y, H:i') ?? 'Never';
    }

    public function lastImportRelative(): ?string
    {
        return $this->lastImportedAt?->diffForHumans();
    }

    /**
     * The line above an empty grid, naming how the map fills in.
     */
    public function emptyWindowNote(): string
    {
        return 'No runs found in the last 16 weeks. The map fills in after your next import.';
    }
}
