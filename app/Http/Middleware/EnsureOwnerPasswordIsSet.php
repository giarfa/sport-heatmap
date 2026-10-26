<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a signed-in owner who has not chosen a password yet to the
 * change-password page.
 *
 * A null password is the first-sign-in state (FR-021): the owner got in with an
 * empty password, and nothing else is reachable until they set one. A no-op
 * for guests — the `auth` middleware runs first wherever this is used.
 */
class EnsureOwnerPasswordIsSet
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->password === null) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
