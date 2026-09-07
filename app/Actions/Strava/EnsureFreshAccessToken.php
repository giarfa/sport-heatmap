<?php

namespace App\Actions\Strava;

use App\Data\StravaTokens;
use App\Exceptions\Strava\StravaOAuthException;
use App\Exceptions\Strava\StravaReconnectRequiredException;
use App\Models\User;
use App\Services\Strava\StravaOAuth;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
    /**
     * How long to wait for a refresh already in flight elsewhere before giving
     * up. Comfortably longer than the 10s cap on the token call itself.
     */
    private const LOCK_SECONDS = 15;

    public function __construct(private readonly StravaOAuth $stravaOAuth) {}

    /**
     * The `?string $rejectedToken` argument is the seam US-003's client uses to
     * force a refresh after a mid-sync 401: the token Strava just rejected.
     *
     * @throws StravaReconnectRequiredException
     */
    public function __invoke(User $user, ?string $rejectedToken = null): string
    {
        if (! $this->mustRefresh($user, $rejectedToken)) {
            return (string) $this->readToken($user, 'strava_access_token');
        }

        /*
         * Strava invalidates the old refresh token the moment it issues a new
         * one, so two requests refreshing at the same time is not a wasted call —
         * it is a dead athlete. The loser of the race would persist a refresh
         * token Strava had already retired, and the only way back would be a full
         * re-authorization. The lock serialises them; the second one through
         * re-reads the row and finds the token the first one stored.
         */
        $lock = Cache::lock('strava:refresh:'.$user->getKey(), self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_SECONDS);
        } catch (LockTimeoutException $exception) {
            throw new StravaReconnectRequiredException(
                'Timed out waiting for a Strava token refresh already in progress.', 0, $exception,
            );
        }

        try {
            $user->refresh();

            if (! $this->mustRefresh($user, $rejectedToken)) {
                return (string) $this->readToken($user, 'strava_access_token');
            }

            return $this->refresh($user);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the stored token needs refreshing before this caller can use it.
     *
     * Takes the rejected token rather than a `bool $force`: a plain force flag
     * would re-refresh unconditionally after the lock and retire the refresh
     * token the winner of a concurrent race just stored — the exact failure this
     * Action exists to prevent. Comparing against the rejected value instead
     * means a caller whose token was already rotated by another process finds a
     * token different from the one it was rejected with, and returns it without
     * refreshing a second time.
     */
    private function mustRefresh(User $user, ?string $rejectedToken): bool
    {
        if ($this->needsRefresh($user)) {
            return true;
        }

        return $rejectedToken !== null && $rejectedToken === (string) $this->readToken($user, 'strava_access_token');
    }

    /**
     * @throws StravaReconnectRequiredException
     */
    private function refresh(User $user): string
    {
        $refreshToken = (string) $this->readToken($user, 'strava_refresh_token');

        if ($refreshToken === '') {
            throw new StravaReconnectRequiredException('No Strava refresh token is stored for this athlete.');
        }

        $current = new StravaTokens(
            accessToken: (string) $this->readToken($user, 'strava_access_token'),
            refreshToken: $refreshToken,
            expiresAt: CarbonImmutable::instance($user->strava_token_expires_at ?? Carbon::now()),
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

        if ($expiresAt === null || $this->readToken($user, 'strava_access_token') === null) {
            return true;
        }

        $margin = (int) config('strava.token_refresh_margin_seconds');

        return $expiresAt->lessThanOrEqualTo(Carbon::now()->addSeconds($margin));
    }

    /**
     * Read one of the `encrypted` token columns.
     *
     * Rotating APP_KEY — or restoring a database into an environment holding a
     * different one — makes every stored token undecryptable. That is a
     * reconnect, not a server fault: without this the guard middleware would 500
     * on the dashboard route with no in-app way back.
     *
     * @throws StravaReconnectRequiredException
     */
    private function readToken(User $user, string $column): ?string
    {
        try {
            /** @var string|null $value */
            $value = $user->getAttribute($column);

            return $value;
        } catch (DecryptException $exception) {
            throw new StravaReconnectRequiredException(
                'The stored Strava tokens could not be decrypted.', 0, $exception,
            );
        }
    }
}
