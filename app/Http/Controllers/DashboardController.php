<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The single screen of this product.
     *
     * A signed-out visit renders the connect screen rather than redirecting to a
     * login page — there is no login page, and a redirect loop is the failure
     * mode this route exists to avoid.
     */
    public function __invoke(): View
    {
        return Auth::check()
            ? view('dashboard')
            : view('strava.connect');
    }
}
