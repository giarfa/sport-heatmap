<?php

namespace App\Data;

use App\Enums\GarminSyncFailure;
use Carbon\CarbonInterface;

/**
 * What FR-007 needs, resolved fresh on every request and never cached: when the
 * newest confirmed CSV import changed the owner's data, and — only when the Garmin
 * sync is configured — when the last sync succeeded and whether the latest attempt
 * failed. Every user-facing string lives here — chip, footer, detail card, warning —
 * so no date arithmetic reaches Blade.
 */
final readonly class ImportStatus
{
    public function __construct(
        public ?CarbonInterface $lastImportedAt,
        public int $runsInWindow,
        public ?CarbonInterface $lastSyncedAt = null,
        public ?GarminSyncFailure $syncFailure = null,
        public bool $syncConfigured = false,
    ) {}

    public function hasImported(): bool
    {
        return $this->lastImportedAt !== null;
    }

    /**
     * The more recent of the last sync and the last import. A sync that wrote nothing
     * still counts: the data was checked, which is what "current" means to the owner.
     */
    public function chipLabel(): string
    {
        if ($this->lastSyncedAt !== null
            && ($this->lastImportedAt === null || $this->lastSyncedAt->greaterThanOrEqualTo($this->lastImportedAt))) {
            return "Last sync {$this->lastSyncedAt->diffForHumans()}";
        }

        if ($this->lastImportedAt !== null) {
            return "Last import {$this->lastImportedAt->diffForHumans()}";
        }

        return $this->syncConfigured ? 'Not synced yet' : 'No runs imported yet';
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
     * The absolute stamp of the last successful sync, in the display timezone.
     */
    public function lastSyncLabel(): string
    {
        return $this->lastSyncedAt
            ?->copy()
            ->setTimezone((string) config('heatmap.display_timezone'))
            ->format('j M Y, H:i') ?? 'Never';
    }

    public function lastSyncRelative(): ?string
    {
        return $this->lastSyncedAt?->diffForHumans();
    }

    /**
     * The banner's first line while the latest finished attempt failed, else null.
     */
    public function syncWarning(): ?string
    {
        return $this->syncFailure?->warning();
    }

    /**
     * The banner's second line.
     */
    public function syncWarningDetail(): ?string
    {
        return $this->syncFailure?->advice();
    }

    /**
     * The line above an empty grid, naming how the map fills in.
     */
    public function emptyWindowNote(): string
    {
        return $this->syncConfigured
            ? 'No runs found in the last 16 weeks. The map fills in after your next sync or import.'
            : 'No runs found in the last 16 weeks. The map fills in after your next import.';
    }
}
