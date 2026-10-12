<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Garmin\CancelGarminImportController;
use App\Http\Controllers\Garmin\ConfirmGarminImportController;
use App\Http\Controllers\Garmin\ShowGarminImportFormController;
use App\Http\Controllers\Garmin\ShowGarminImportReviewController;
use App\Http\Controllers\Garmin\ShowGarminImportSummaryController;
use App\Http\Controllers\Garmin\UploadGarminCsvController;
use App\Http\Controllers\Strava\StravaCallbackController;
use App\Http\Controllers\Strava\StravaRedirectController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)
    ->middleware('strava.fresh')
    ->name('dashboard');

/*
 * Both OAuth routes are throttled, not just the callback: the redirect route is
 * the cheaper one to hammer, and neither is ever hit more than a handful of
 * times by a legitimate athlete.
 */
Route::middleware('throttle:strava-oauth')->group(function (): void {
    Route::get('/auth/strava/redirect', StravaRedirectController::class)->name('strava.redirect');
    Route::get('/auth/strava/callback', StravaCallbackController::class)->name('strava.callback');
});

/*
 * The Garmin CSV import is owner-only: auth refuses guests (a GET is redirected
 * home, see bootstrap/app.php), and strava.fresh re-checks the allowlist on
 * every request. CSRF comes from the web group. No route takes an import id —
 * the pending upload lives in the owner's session only.
 */
Route::middleware(['auth', 'strava.fresh'])
    ->prefix('import/garmin')
    ->name('garmin.import.')
    ->group(function (): void {
        Route::get('/', ShowGarminImportFormController::class)->name('create');
        Route::post('/', UploadGarminCsvController::class)->middleware('throttle:garmin-import')->name('store');
        Route::get('/review', ShowGarminImportReviewController::class)->name('review');
        Route::post('/confirm', ConfirmGarminImportController::class)->middleware('throttle:garmin-import')->name('confirm');
        Route::post('/cancel', CancelGarminImportController::class)->name('cancel');
        Route::get('/summary', ShowGarminImportSummaryController::class)->name('summary');
    });
