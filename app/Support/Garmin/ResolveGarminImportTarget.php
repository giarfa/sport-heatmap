<?php

namespace App\Support\Garmin;

use App\Models\User;
use App\Support\Strava\ResolveDisplayedAthlete;

/**
 * Decides whose activities a Garmin import plans against and writes to: the
 * signed-in owner's own, else the athlete the home page displays (FR-017),
 * else nobody yet — the confirmed first import then creates the CSV-only
 * athlete.
 *
 * The review and the confirm both resolve through this class, so the athlete a
 * visitor was shown conflicts for is the athlete the confirm writes to.
 */
final readonly class ResolveGarminImportTarget
{
    public function __construct(private ResolveDisplayedAthlete $resolveDisplayedAthlete) {}

    public function __invoke(?User $signedIn): ?User
    {
        /*
         * The guest branch goes through ResolveDisplayedAthlete, which selects
         * only id and strava_athlete_id: a signed-out import can never load,
         * decrypt, or refresh a token, because none is in the model it gets.
         */
        return $signedIn ?? ($this->resolveDisplayedAthlete)();
    }
}
