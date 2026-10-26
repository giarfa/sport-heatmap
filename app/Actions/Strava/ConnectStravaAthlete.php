<?php

namespace App\Actions\Strava;

use App\Data\StravaTokens;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The only write path in the OAuth flow: stores the Strava identity and tokens
 * on the signed-in owner.
 *
 * It is deliberately unaware of the allowlist: the caller decides whether the
 * athlete may connect, and this class only runs once that decision has passed.
 * Keeping "allowlist first, persistence second" as two separate objects makes
 * the ordering structural rather than a comment inside one long method.
 */
class ConnectStravaAthlete
{
    public function __invoke(User $owner, StravaTokens $tokens): User
    {
        /*
         * Assigned explicitly: the Strava columns are kept out of $fillable so
         * that no request body can plant an athlete id or a token pair. The
         * owner's own name and email are never overwritten by Strava's, and no
         * user is created here — the owner is the one account.
         */
        $owner->strava_athlete_id = (string) $tokens->athleteId;
        $owner->strava_access_token = $tokens->accessToken;
        $owner->strava_refresh_token = $tokens->refreshToken;
        $owner->strava_token_expires_at = Carbon::instance($tokens->expiresAt);

        $owner->save();

        return $owner;
    }
}
