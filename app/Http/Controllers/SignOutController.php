<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SignOutController extends Controller
{
    /**
     * End the owner's session and return to the dashboard.
     *
     * Idempotent: a guest posting here gets a fresh session and the dashboard,
     * not an error. It never touches the Strava token columns — signing out is
     * not deauthorisation (FR-012), and the nightly sync runs on those tokens
     * with no session at all.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard');
    }
}
