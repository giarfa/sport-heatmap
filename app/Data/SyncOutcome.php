<?php

namespace App\Data;

/**
 * The result of reconciling one athlete's window — a return value, not an
 * external payload, but it lives alongside StravaActivity as the counterpart
 * DTO on the way back out of the boundary.
 */
final readonly class SyncOutcome
{
    public function __construct(
        public int $upserted,
        public int $deleted,
    ) {}

    /**
     * Aggregate this outcome with another, so the command can sum counts across
     * every athlete it synced in one run.
     */
    public function plus(self $other): self
    {
        return new self(
            upserted: $this->upserted + $other->upserted,
            deleted: $this->deleted + $other->deleted,
        );
    }
}
