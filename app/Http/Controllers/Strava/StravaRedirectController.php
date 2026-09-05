<?php

namespace App\Http\Controllers\Strava;

use App\Http\Controllers\Controller;
use App\Services\Strava\StravaOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StravaRedirectController extends Controller
{
    /**
     * Start the authorization-code flow.
     *
     * The state value is the CSRF guard on the callback: it is minted here,
     * parked in the session, and compared with hash_equals on the way back.
     */
    public function __invoke(Request $request, StravaOAuth $stravaOAuth): RedirectResponse
    {
        $state = Str::random(40);

        $request->session()->put('strava.oauth_state', $state);

        return redirect()->away($stravaOAuth->authorizationUrl($state));
    }
}
