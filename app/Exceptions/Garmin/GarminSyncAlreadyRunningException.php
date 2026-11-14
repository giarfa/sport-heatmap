<?php

namespace App\Exceptions\Garmin;

use RuntimeException;

/**
 * A Garmin sync was asked for while another one holds the lock. Nothing was recorded
 * and nothing was called; the message is the sentence the owner reads.
 */
class GarminSyncAlreadyRunningException extends RuntimeException
{
    public static function make(): self
    {
        return new self('A Garmin sync is already running — nothing started.');
    }
}
