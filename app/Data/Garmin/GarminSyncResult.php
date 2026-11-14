<?php

namespace App\Data\Garmin;

use App\Enums\GarminSyncFailure;
use App\Enums\GarminSyncOutcome;

/**
 * How one sync attempt ended: the verdict, the counts of what it did (also when it
 * failed half-way), and the cause when it failed.
 */
final readonly class GarminSyncResult
{
    public function __construct(
        public GarminSyncOutcome $outcome,
        public GarminSyncCounts $counts,
        public bool $fullHistory,
        public ?GarminSyncFailure $failure = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === GarminSyncOutcome::Succeeded;
    }

    /**
     * The sentence Sync now flashes: what a finished sync did, or why it failed. The
     * unreadable count is mentioned only when something was unreadable.
     */
    public function summary(): string
    {
        if ($this->failure !== null) {
            return "Sync failed: {$this->failure->warning()}";
        }

        $parts = [
            "{$this->counts->new} new",
            "{$this->counts->updated} updated",
            "{$this->counts->linked} linked",
            "{$this->counts->ignored} ignored",
            "{$this->counts->futureDated} future-dated",
        ];

        if ($this->counts->invalid > 0) {
            $parts[] = "{$this->counts->invalid} unreadable";
        }

        return 'Sync finished: '.implode(', ', $parts).'.';
    }
}
