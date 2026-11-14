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
}
