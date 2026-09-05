<?php

namespace App\Exceptions\Strava;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The stored refresh token is no longer usable — typically because the athlete
 * revoked this application on Strava's side.
 *
 * It renders rather than bubbling into a 500: an expired connection is an
 * expected state of this product, not a server fault. Rendering the connect
 * screen instead of redirecting keeps the reason visible in one round trip.
 */
class StravaReconnectRequiredException extends RuntimeException
{
    public function render(Request $request): Response
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->view('strava.connect', ['reconnect' => true]);
    }
}
