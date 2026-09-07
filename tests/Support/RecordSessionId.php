<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the session id as it stood when the request entered the route.
 *
 * Pushed onto the `web` group by the session-fixation test, so it runs *after*
 * StartSession and before the controller. Comparing this id with the one taken
 * inside the Login event is what makes that test assert the fixation boundary
 * itself rather than any particular call the controller happens to make.
 */
class RecordSessionId
{
    public static ?string $id = null;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        self::$id = $request->session()->getId();

        return $next($request);
    }
}
