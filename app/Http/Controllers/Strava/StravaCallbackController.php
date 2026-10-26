<?php

namespace App\Http\Controllers\Strava;

use App\Actions\Strava\ConnectStravaAthlete;
use App\Exceptions\Strava\StravaOAuthException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Strava\StravaOAuth;
use App\Support\Strava\AthleteAllowlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches Strava tokens to the signed-in owner. It signs nobody in: the
 * password form is the only way to get a session (US-020).
 *
 * The gates below run in a fixed order and **nothing is written until the last
 * one passes**. That ordering is what makes "no tokens are stored" assertable
 * by inspecting the owner's columns after a rejected attempt rather than by
 * inspecting intent.
 */
class StravaCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        StravaOAuth $stravaOAuth,
        AthleteAllowlist $allowlist,
        ConnectStravaAthlete $connectStravaAthlete,
    ): Response {
        /*
         * The state is consumed first, on every path including cancellation:
         * leaving it in the session on an early return would keep one live and
         * replayable, which is the one thing a single-use nonce must never be.
         */
        $expectedState = $this->pullExpectedState($request);

        // 1. The athlete pressed Cancel on Strava's consent screen.
        if ($this->queryString($request, 'error') === 'access_denied') {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Strava authorization was cancelled. Nothing was stored.');
        }

        // 2. CSRF: the state must match the one this session minted, and is single-use.
        $providedState = $this->queryString($request, 'state');

        if ($expectedState === '' || $providedState === '' || ! hash_equals($expectedState, $providedState)) {
            abort(403, 'The Strava authorization state did not match.');
        }

        // 3. The athlete unticked the permission box: without read access there is nothing to sync.
        $grantedScope = $this->queryString($request, 'scope');
        $requiredScope = (string) config('strava.scope');
        $grantedScopes = array_filter(array_map('trim', explode(',', $grantedScope)));

        if (! in_array($requiredScope, $grantedScopes, strict: true)) {
            return response()->view('strava.rejected', [
                'reason' => 'scope',
                'requiredScope' => $requiredScope,
            ], Response::HTTP_FORBIDDEN);
        }

        /*
         * 3b. The request side can only ever ask for activity:read_all
         *     (config/strava.php keeps it as a literal), but the *response* side
         *     is attacker-controlled: the athlete can hand-edit the scope on
         *     Strava's consent URL and come back holding a write-capable token.
         *     Refusing it here is what makes "read-only" a property of what is
         *     stored rather than only of what was asked for. Surplus *read*
         *     scopes are tolerated — Strava may add its own, and they widen
         *     nothing.
         */
        if (array_filter($grantedScopes, $this->isWriteScope(...)) !== []) {
            return response()->view('strava.rejected', [
                'reason' => 'write_scope',
                'requiredScope' => $requiredScope,
            ], Response::HTTP_FORBIDDEN);
        }

        // 4. No code means there is nothing to exchange — refuse locally rather
        //    than spending a round trip on a request that can only fail.
        $code = $this->queryString($request, 'code');

        if ($code === '') {
            return response()->view('strava.rejected', [
                'reason' => 'exchange',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 5. Exchange it. Strava embeds the athlete object in the response, so the
        //    id is known before anything at all is persisted.
        try {
            $tokens = $stravaOAuth->exchangeAuthorizationCode($code, $grantedScope);
        } catch (StravaOAuthException $exception) {
            Log::warning('Strava token exchange failed.', ['reason' => $exception->getMessage()]);

            return response()->view('strava.rejected', [
                'reason' => 'exchange',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 6. The access decision — still no write of any kind has happened.
        if ($tokens->athleteId === null || ! $allowlist->allows($tokens->athleteId)) {
            return response()->view('strava.rejected', [
                'reason' => 'allowlist',
                'athleteId' => $tokens->athleteId,
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var User $owner */
        $owner = $request->user();

        /*
         * A Strava id another row already holds would break the unique index and
         * hand this owner somebody else's identity. No route creates users, so
         * the rejected screen is the whole answer and nothing is written.
         */
        if (User::query()->where('strava_athlete_id', (string) $tokens->athleteId)->whereKeyNot($owner->id)->exists()) {
            return response()->view('strava.rejected', [
                'reason' => 'taken',
            ], Response::HTTP_FORBIDDEN);
        }

        /*
         * 7. Only now: persist onto the signed-in owner. This callback is no
         * longer a sign-in: it neither creates a session nor regenerates one,
         * and the destination is the literal dashboard route, never
         * redirect()->intended(), because an absolute intended URL passes
         * through the generator untouched.
         */
        $connectStravaAthlete($owner, $tokens);

        return redirect()->route('dashboard');
    }

    /**
     * Read a query parameter as a string, treating anything else as absent.
     *
     * Every parameter here is attacker-supplied and `?state[]=x` arrives as an
     * array. Casting one to string raises a warning that Laravel's error handler
     * promotes to an exception, so a plain `(string)` cast hands any anonymous
     * caller a 500 — and a stack trace wherever APP_DEBUG is on.
     */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    private function pullExpectedState(Request $request): string
    {
        $state = $request->session()->pull('strava.oauth_state');

        return is_string($state) ? $state : '';
    }

    /**
     * Strava spells write access as `activity:write` and `profile:write`, so the
     * suffix is the reliable marker rather than an allowlist of known scopes —
     * a scope Strava adds later is refused by default instead of by omission.
     */
    private function isWriteScope(string $scope): bool
    {
        return str_ends_with($scope, ':write') || $scope === 'write';
    }
}
