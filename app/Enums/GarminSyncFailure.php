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

    /**
     * The second line of the banner: what the map is showing and what to do. Kept beside
     * warning() so the copy of a failure lives in one file.
     */
    public function advice(): string
    {
        return match ($this) {
            self::KeyRejected => 'Garmin refused the key, so the map shows the runs synced before. Create a new key in Garmin Connect and set GARMIN_API_KEY.',
            self::Unreachable => 'Garmin did not answer, so the map shows the runs synced before. The next daily sync tries again, or press Sync now.',
            self::Unexpected => 'Garmin answered with something this app cannot read, so the map shows the runs synced before. Press Sync now to try again.',
        };
    }
}
