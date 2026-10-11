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
 * The standing guard on every signed-in request.
 *
 * It does two things on each request, in this order: re-checks that the signed-in
 * athlete is still allowed in at all, then makes sure their Strava token is usable
 * for whatever the route does next. A dead token signs the athlete out and sends
 * them to the public home page with a one-off expiry banner.
 *
 * A no-op for guests — the public dashboard's read path must never reach the
 * token refresh — and free while the stored token is still valid.
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
         * session down: rendering a reconnect prompt while leaving the athlete
         * signed in with a dead token is a loop, and the teardown belongs where
         * the request is, not inside an exception.
         *
         * Then Post/Redirect/Get onto the public home page: the flash lands in
         * the session invalidate() has just regenerated, the URL stays /, and a
         * reload does not repeat the banner. Rejected: continuing with $next as
         * a guest, which would couple this middleware to the fact that the
         * current route happens to serve guests; and the old connect-only
         * screen, which hid the owner's own map behind a sign-in (US-007).
         */
        try {
            ($this->ensureFreshAccessToken)($user);
        } catch (StravaReconnectRequiredException) {
            $this->endSession($request);

            return redirect()->route('dashboard')->with('strava.reconnect', true);
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
