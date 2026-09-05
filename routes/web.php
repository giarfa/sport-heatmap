<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Strava\StravaCallbackController;
use App\Http\Controllers\Strava\StravaRedirectController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

/*
 * Both OAuth routes are throttled, not just the callback: the redirect route is
 * the cheaper one to hammer, and neither is ever hit more than a handful of
 * times by a legitimate athlete.
 */
Route::middleware('throttle:strava-oauth')->group(function (): void {
    Route::get('/auth/strava/redirect', StravaRedirectController::class)->name('strava.redirect');
    Route::get('/auth/strava/callback', StravaCallbackController::class)->name('strava.callback');
});
