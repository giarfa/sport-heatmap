<?php

namespace App\Actions\Strava;

use App\Data\StravaTokens;
use App\Exceptions\Strava\StravaOAuthException;
use App\Exceptions\Strava\StravaReconnectRequiredException;
use App\Models\User;
use App\Services\Strava\StravaOAuth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Returns an access token that is usable right now.
 *
 * This is the seam every Strava call goes through — US-003's client calls it
 * before each request. While the stored token is comfortably valid it costs zero
 * HTTP requests.
 */
class EnsureFreshAccessToken
{
    public function __construct(private readonly StravaOAuth $stravaOAuth) {}

    /**
     * @throws StravaReconnectRequiredException
     */
    public function __invoke(User $user): string
    {
        $refreshToken = $user->strava_refresh_token;

        if ($refreshToken === null || $refreshToken === '') {
            throw new StravaReconnectRequiredException('No Strava refresh token is stored for this athlete.');
        }

        if (! $this->needsRefresh($user)) {
            return (string) $user->strava_access_token;
        }

        $current = new StravaTokens(
            accessToken: (string) $user->strava_access_token,
            refreshToken: $refreshToken,
            expiresAt: CarbonImmutable::now(),
            athleteId: $user->strava_athlete_id,
        );

        try {
            $refreshed = $this->stravaOAuth->refreshAccessToken($current);
        } catch (StravaOAuthException $exception) {
            /*
             * The athlete id and the HTTP status are the whole diagnostic here.
             * Token values never reach a log line.
             */
            Log::warning('Refreshing the Strava access token failed.', [
                'athlete_id' => $user->strava_athlete_id,
                'reason' => $exception->getMessage(),
            ]);

            throw new StravaReconnectRequiredException('The stored Strava refresh token was rejected.', 0, $exception);
        }

        /*
         * Both halves are persisted: Strava rotates the refresh token on every
         * refresh, so storing only the access token would break the *next* one.
         */
        $user->strava_access_token = $refreshed->accessToken;
        $user->strava_refresh_token = $refreshed->refreshToken;
        $user->strava_token_expires_at = Carbon::instance($refreshed->expiresAt);
        $user->save();

        return $refreshed->accessToken;
    }

    /**
     * A token that merely has not expired yet is not good enough: without a
     * margin, one that passes this check can still expire mid-request.
     */
    private function needsRefresh(User $user): bool
    {
        $expiresAt = $user->strava_token_expires_at;

        if ($expiresAt === null || $user->strava_access_token === null) {
            return true;
        }

        $margin = (int) config('strava.token_refresh_margin_seconds');

        return $expiresAt->lessThanOrEqualTo(Carbon::now()->addSeconds($margin));
    }
}
