<?php

namespace App\Enums;

/**
 * What started a Garmin sync attempt.
 */
enum GarminSyncTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
