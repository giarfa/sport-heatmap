<?php

namespace App\Data\Garmin;

/**
 * What a sync did with the activities it read.
 */
final readonly class GarminSyncCounts
{
    public function __construct(
        public int $new = 0,
        public int $updated = 0,
        public int $linked = 0,
        public int $ignored = 0,
        public int $futureDated = 0,
        public int $invalid = 0,
    ) {}

    /**
     * These counts and the other's, added column by column.
     */
    public function plus(self $other): self
    {
        return new self(
            new: $this->new + $other->new,
            updated: $this->updated + $other->updated,
            linked: $this->linked + $other->linked,
            ignored: $this->ignored + $other->ignored,
            futureDated: $this->futureDated + $other->futureDated,
            invalid: $this->invalid + $other->invalid,
        );
    }

    /**
     * Whether any run row was created or changed: the only counts that make a cached
     * grid stale. Ignored, future-dated and invalid activities never reach the table.
     */
    public function wroteRuns(): bool
    {
        return $this->new + $this->updated + $this->linked > 0;
    }

    /**
     * The columns of `garmin_syncs`.
     *
     * @return array{new_count: int, updated_count: int, linked_count: int, ignored_count: int, future_dated_count: int, invalid_count: int}
     */
    public function toColumns(): array
    {
        return [
            'new_count' => $this->new,
            'updated_count' => $this->updated,
            'linked_count' => $this->linked,
            'ignored_count' => $this->ignored,
            'future_dated_count' => $this->futureDated,
            'invalid_count' => $this->invalid,
        ];
    }
}
