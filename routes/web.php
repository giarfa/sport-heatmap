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
 * The Garmin CSV import is open to signed-out visitors by the owner's choice
 * (PRD Security, accepted risk — US-011): a guest imports into the displayed
 * athlete, or creates the CSV-only athlete when none is displayed. What still
 * guards it: CSRF from the web group, the 5 MB rule in the Form Request, the
 * garmin-import throttle (per IP for guests) on upload, review, and confirm
 * (the review re-plans the upload against the stored runs: work a guest could
 * otherwise repeat without limit), and the
 * session binding — no route takes an import or an athlete id, so a request
 * only reaches the pending upload in its own session. strava.fresh stays: a
 * no-op for guests, it still re-checks a signed-in owner's allowlist and token.
 */
Route::middleware(['strava.fresh'])
    ->prefix('import/garmin')
    ->name('garmin.import.')
    ->group(function (): void {
        Route::get('/', ShowGarminImportFormController::class)->name('create');
        Route::post('/', UploadGarminCsvController::class)->middleware('throttle:garmin-import')->name('store');
        Route::get('/review', ShowGarminImportReviewController::class)->middleware('throttle:garmin-import')->name('review');
        Route::post('/confirm', ConfirmGarminImportController::class)->middleware('throttle:garmin-import')->name('confirm');
        Route::post('/cancel', CancelGarminImportController::class)->name('cancel');
        Route::get('/summary', ShowGarminImportSummaryController::class)->name('summary');
    });
