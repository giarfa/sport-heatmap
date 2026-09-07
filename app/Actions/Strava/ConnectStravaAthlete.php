<?php

namespace App\Actions\Strava;

use App\Data\StravaTokens;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only write path in the OAuth flow.
 *
 * It is deliberately unaware of the allowlist: the caller decides whether the
 * athlete may connect, and this class only runs once that decision has passed.
 * Keeping "allowlist first, persistence second" as two separate objects makes
 * the ordering structural rather than a comment inside one long method.
 */
class ConnectStravaAthlete
{
    public function __invoke(StravaTokens $tokens): User
    {
        $athleteId = (string) $tokens->athleteId;

        return DB::transaction(function () use ($tokens, $athleteId): User {
            $user = User::query()->where('strava_athlete_id', $athleteId)->first() ?? new User;

            $user->name = $tokens->athleteName ?? 'Athlete '.$athleteId;

            if (! $user->exists) {
                /*
                 * Strava exposes no email address under activity:read_all, and
                 * users.email is NOT NULL UNIQUE. .invalid is the RFC 2606
                 * reserved TLD, so this address can never resolve and nothing is
                 * ever sent to it.
                 */
                $user->email = sprintf('athlete-%s@strava.invalid', $athleteId);
                $user->email_verified_at = now();
            }

            /*
             * Assigned explicitly: the Strava columns are kept out of $fillable so
             * that no request body can plant an athlete id or a token pair.
             */
            $user->strava_athlete_id = $athleteId;
            $user->strava_access_token = $tokens->accessToken;
            $user->strava_refresh_token = $tokens->refreshToken;
            $user->strava_token_expires_at = Carbon::instance($tokens->expiresAt);

            $user->save();

            return $user;
        });
    }
}
