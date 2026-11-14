<?php

namespace App\Enums;

/**
 * Where a Garmin sync attempt stands. `Running` is the only state with no end time.
 */
enum GarminSyncOutcome: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
