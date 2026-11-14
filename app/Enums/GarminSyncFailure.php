<?php

namespace App\Enums;

/**
 * Why a Garmin sync attempt failed — a closed list, never free text, so a failed
 * attempt can be stored and shown without a secret, a URL or a response body.
 */
enum GarminSyncFailure: string
{
    case KeyRejected = 'key_rejected';
    case Unreachable = 'unreachable';
    case Unexpected = 'unexpected';

    /**
     * The sentence the dashboard shows while this is the latest attempt's failure.
     * The one place the copy lives.
     */
    public function warning(): string
    {
        return match ($this) {
            self::KeyRejected => 'Garmin key rejected — renew it',
            self::Unreachable => 'Garmin unreachable',
            self::Unexpected => 'Garmin sync failed',
        };
    }
}
