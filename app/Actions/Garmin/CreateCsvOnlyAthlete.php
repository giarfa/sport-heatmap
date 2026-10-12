<?php

namespace App\Actions\Garmin;

use App\Models\User;

/**
 * Returns the local athlete that owns Garmin CSV imports made while no athlete
 * is displayed, creating it on first use. It has no Strava identity and no
 * tokens, so the nightly sync never selects it.
 */
final readonly class CreateCsvOnlyAthlete
{
    public const NAME = 'Garmin CSV athlete';

    public const EMAIL = 'csv-athlete@garmin.invalid';

    public function __invoke(): User
    {
        /*
         * users.name and users.email are NOT NULL, and a migration loosening
         * them for one row was rejected: placeholders fill both. .invalid is
         * the RFC 2606 reserved TLD (the same convention as the Strava
         * connect's @strava.invalid), so nothing is ever sent to it. The fixed
         * address plus the UNIQUE index on users.email is what makes a second
         * CSV-only athlete impossible: firstOrCreate reuses the first.
         */
        return User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => self::NAME],
        );
    }
}
