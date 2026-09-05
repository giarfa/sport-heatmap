<?php

namespace App\Http\Controllers\Strava;

use App\Actions\Strava\ConnectStravaAthlete;
use App\Exceptions\Strava\StravaOAuthException;
use App\Http\Controllers\Controller;
use App\Services\Strava\StravaOAuth;
use App\Support\Strava\AthleteAllowlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single place a session can be created, and therefore the single place the
 * access decision is made.
 *
 * The gates below run in a fixed order and **nothing is written until the last
 * one passes**. That ordering is what makes the spec's "no session exists, no
 * tokens are stored, and no user record is written" assertable by counting rows
 * after a rejected attempt rather than by inspecting intent.
 */
class StravaCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        StravaOAuth $stravaOAuth,
        AthleteAllowlist $allowlist,
        ConnectStravaAthlete $connectStravaAthlete,
    ): Response {
        // 1. The athlete pressed Cancel on Strava's consent screen.
        if ($request->query('error') === 'access_denied') {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Strava authorization was cancelled. Nothing was stored.');
        }

        // 2. CSRF: the state must match the one this session minted, and is single-use.
        $expectedState = (string) $request->session()->pull('strava.oauth_state', '');
        $providedState = (string) $request->query('state', '');

        if ($expectedState === '' || $providedState === '' || ! hash_equals($expectedState, $providedState)) {
            abort(403, 'The Strava authorization state did not match.');
        }

        // 3. The athlete unticked the permission box: without read access there is nothing to sync.
        $grantedScope = (string) $request->query('scope', '');
        $requiredScope = (string) config('strava.scope');

        if (! in_array($requiredScope, array_map('trim', explode(',', $grantedScope)), strict: true)) {
            return response()->view('strava.rejected', [
                'reason' => 'scope',
                'requiredScope' => $requiredScope,
            ], Response::HTTP_FORBIDDEN);
        }

        // 4. Exchange the code. Strava embeds the athlete object here, so the id
        //    is known before anything at all is persisted.
        try {
            $tokens = $stravaOAuth->exchangeAuthorizationCode((string) $request->query('code', ''), $grantedScope);
        } catch (StravaOAuthException $exception) {
            Log::warning('Strava token exchange failed.', ['reason' => $exception->getMessage()]);

            return response()->view('strava.rejected', [
                'reason' => 'exchange',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 5. The access decision — still no write of any kind has happened.
        if ($tokens->athleteId === null || ! $allowlist->allows($tokens->athleteId)) {
            return response()->view('strava.rejected', [
                'reason' => 'allowlist',
                'athleteId' => $tokens->athleteId,
            ], Response::HTTP_FORBIDDEN);
        }

        // 6. Only now: persist, then log in on a fresh session id.
        $user = $connectStravaAthlete($tokens);

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
