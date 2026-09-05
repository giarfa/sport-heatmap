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
}
