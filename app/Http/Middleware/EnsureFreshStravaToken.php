<?php

namespace App\Http\Middleware;

use App\Actions\Strava\EnsureFreshAccessToken;
use App\Exceptions\Strava\StravaReconnectRequiredException;
use App\Models\User;
use App\Support\Strava\AthleteAllowlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The standing guard on every authenticated screen.
 *
 * It does two things on each request, in this order: re-checks that the signed-in
 * athlete is still allowed in at all, then makes sure their Strava token is usable
 * for whatever the route does next.
 *
 * A no-op for guests, and free while the stored token is still valid.
 */
class EnsureFreshStravaToken
{
    public function __construct(
        private readonly EnsureFreshAccessToken $ensureFreshAccessToken,
        private readonly AthleteAllowlist $allowlist,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        /*
         * The allowlist is re-evaluated on every request, not only at connect
         * time. It is the only access control in this product, and the session
         * plus the remember-me cookie it mints outlive an edit to .env by weeks —
         * so checking it once would make removal from the allowlist a change that
         * takes effect only after the athlete happens to log out.
         */
        $athleteId = $user->strava_athlete_id;

        if ($athleteId === null || ! $this->allowlist->allows($athleteId)) {
            $this->endSession($request);

            return response()->view('strava.rejected', [
                'reason' => 'allowlist',
                'athleteId' => $athleteId,
            ], Response::HTTP_FORBIDDEN);
        }

        /*
         * This middleware, not the exception's own render(), is what tears the
         * session down: rendering a reconnect screen while leaving the athlete
         * signed in with a dead token is a loop, and the teardown belongs where
         * the request is, not inside an exception.
         */
        try {
            ($this->ensureFreshAccessToken)($user);
        } catch (StravaReconnectRequiredException) {
            $this->endSession($request);

            return response()->view('strava.connect', ['reconnect' => true]);
        }

        return $next($request);
    }

    /**
     * Drop the session and the remember-me cookie together — leaving the latter
     * would sign the athlete straight back in on the next request.
     */
    private function endSession(Request $request): void
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
