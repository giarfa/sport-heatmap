<?php

namespace App\Http\Middleware;

use App\Actions\Strava\EnsureFreshAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the authenticated athlete's Strava token usable for whatever the route
 * does next, and turns a dead refresh token into the reconnect screen rather
 * than a 500 halfway through rendering.
 *
 * A no-op for guests, and free while the stored token is still valid.
 */
class EnsureFreshStravaToken
{
    public function __construct(private readonly EnsureFreshAccessToken $ensureFreshAccessToken) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            ($this->ensureFreshAccessToken)($user);
        }

        return $next($request);
    }
}
