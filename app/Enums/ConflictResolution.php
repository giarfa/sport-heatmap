<?php

namespace App\Enums;

/**
 * The owner's answer for one Garmin CSV row that clashes with a stored run.
 */
enum ConflictResolution: string
{
    case Overwrite = 'overwrite';
    case Skip = 'skip';
}
