<?php

namespace App\Data;

use App\Enums\SyncHealth;
use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Carbon\CarbonInterface;

/**
 * The single decision FR-007 needs, resolved fresh on every request from the
 * live `sync_runs` table and never cached: whether the dashboard can say
 * "synced {relative}" or must warn that the map below is not current. Every
 * user-facing string lives here — chip, banner, detail card — so no date
 * arithmetic or copy reaches Blade.
 */
final readonly class SyncStatus
{
    private function __construct(
        public SyncHealth $health,
        public ?SyncRun $lastRun,
        public ?SyncRun $lastSuccessfulRun,
        public int $runsInWindow,
        public bool $isSyncing,
    ) {}

    public static function from(?SyncRun $lastRun, ?SyncRun $lastSuccessfulRun, int $runsInWindow, CarbonInterface $now): self
    {
        /*
         * Precedence matters: a failure that follows a recent success must
         * still warn, because the map is now frozen at the last good sync.
         * A run in flight with no success behind it reads as "never synced"
         * from the athlete's point of view, not a fifth state.
         */
        $health = match (true) {
            $lastRun === null => SyncHealth::NeverSynced,
            $lastRun->status === SyncRunStatus::Failed => SyncHealth::Failed,
            $lastSuccessfulRun === null => SyncHealth::NeverSynced,
            /*
             * Strict boundary: exactly stale_after_hours old is still
             * healthy, one second more is stale. lt() is strict-less-than,
             * so an exact match on the threshold falls through to Healthy.
             */
            $lastSuccessfulRun->finished_at?->lt(
                $now->copy()->subHours((int) config('strava.stale_after_hours'))
            ) === true => SyncHealth::Stale,
            default => SyncHealth::Healthy,
        };

        return new self(
            $health,
            $lastRun,
            $lastSuccessfulRun,
            $runsInWindow,
            isSyncing: $lastRun?->status === SyncRunStatus::Running,
        );
    }

    public function needsWarning(): bool
    {
        return $this->health !== SyncHealth::Healthy;
    }

    /**
     * The only moment the product has grid geometry to hold open and no data
     * to put in it: a connected athlete's first sync, still in flight.
     */
    public function showsSkeleton(): bool
    {
        return $this->isSyncing && $this->lastSuccessfulRun === null;
    }

    public function hasRuns(): bool
    {
        return $this->lastRun !== null;
    }

    /**
     * The header chip is the only carrier of this information when healthy;
     * once a banner is on the page it repeats it, which is why
     * {@see self::chipIsRedundant()} hides it there instead.
     */
    public function chipLabel(): string
    {
        return match ($this->health) {
            SyncHealth::NeverSynced => 'Never synced',
            SyncHealth::Failed => "Sync failed {$this->relative($this->lastRun?->finished_at)}",
            SyncHealth::Stale, SyncHealth::Healthy => "Synced {$this->relative($this->lastSuccessfulRun?->finished_at)}",
        };
    }

    /**
     * True whenever a warning banner is also on the page: the chip would be a
     * second announcement of the same fact, so it is hidden rather than read
     * twice by a screen reader.
     */
    public function chipIsRedundant(): bool
    {
        return $this->needsWarning();
    }

    /**
     * The lowercase variant the footer prints below 640 px, where the header
     * chip is hidden.
     */
    public function footerLabel(): string
    {
        return lcfirst($this->chipLabel());
    }

    public function warningTitle(): string
    {
        return match ($this->health) {
            SyncHealth::Failed => "Last sync failed {$this->relative($this->lastRun?->finished_at)}",
            SyncHealth::Stale => "Last successful sync was {$this->relative($this->lastSuccessfulRun?->finished_at)}",
            SyncHealth::NeverSynced => 'Waiting for the first sync',
            SyncHealth::Healthy => '',
        };
    }

    public function warningReason(): string
    {
        return match ($this->health) {
            SyncHealth::Failed => $this->failedReason(),
            SyncHealth::Stale => sprintf(
                'The nightly sync has not completed since %s. The map below is showing data from then.',
                $this->onDate($this->lastSuccessfulRun?->finished_at),
            ),
            SyncHealth::NeverSynced => sprintf(
                'No sync has completed yet. The nightly job runs at %d:00 %s.',
                (int) config('strava.sync_hour'),
                (string) config('strava.sync_timezone'),
            ),
            SyncHealth::Healthy => '',
        };
    }

    /**
     * Every warning state offers the same single control: re-running OAuth is
     * idempotent and harmless, and it is the fix for the most common failure
     * (a revoked or rejected refresh token). FR-010's manual re-sync button is
     * out of scope for this spec.
     */
    public function recoveryLabel(): string
    {
        return 'Reconnect Strava';
    }

    /*
     * The chip and the staleness check above both read finished_at — the
     * moment data actually became current — while the detail card below reads
     * started_at, which is what the mockup prints and what the table is
     * indexed on. A run in flight has no finished_at yet, so the detail card
     * would have nothing to show if it read the same field.
     */

    /**
     * The absolute "Last run" stamp the detail card prints, in the sync
     * timezone — the sync hour is expressed in it, so a 04:12 row must not
     * print as 02:12.
     */
    public function lastRunLabel(): string
    {
        if ($this->lastRun === null) {
            return 'Never';
        }

        return $this->lastRun->started_at
            ->copy()
            ->setTimezone((string) config('strava.sync_timezone'))
            ->format('j M Y, H:i');
    }

    public function lastRunRelative(): ?string
    {
        return $this->lastRun?->started_at->diffForHumans();
    }

    public function outcomeLabel(): string
    {
        return $this->lastRun?->status->label() ?? '—';
    }

    public function outcomeModifier(): ?string
    {
        return $this->lastRun?->status->dotModifier();
    }

    private function failedReason(): string
    {
        $errorMessage = (string) ($this->lastRun->error_message ?? '');

        if ($this->lastSuccessfulRun === null) {
            return trim("{$errorMessage} No sync has ever completed, so the map below is empty.");
        }

        return trim(sprintf(
            '%s The map below is showing data from %s.',
            $errorMessage,
            $this->onDate($this->lastSuccessfulRun->finished_at),
        ));
    }

    private function relative(?CarbonInterface $moment): string
    {
        return $moment?->diffForHumans() ?? '';
    }

    private function onDate(?CarbonInterface $moment): string
    {
        return $moment?->format('j M') ?? '';
    }
}
