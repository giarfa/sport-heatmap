<?php

namespace App\Enums;

/**
 * The outcome of a single sync attempt.
 *
 * FR-007 branches on these three states to render the dashboard's sync-status
 * indicator: healthy, in flight, or an explicit failure warning.
 */
enum SyncRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * The word the sync detail card prints for this outcome.
     */
    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    /**
     * The CSS modifier for the outcome dot — `--ok` reuses the healthy chip
     * colour, `--warn` reuses the amber warning ink already committed for the
     * stale/failed banner, so a failed or in-flight run never invents a third
     * colour meaning.
     */
    public function dotModifier(): string
    {
        return match ($this) {
            self::Succeeded => 'ok',
            self::Failed, self::Running => 'warn',
        };
    }
}
