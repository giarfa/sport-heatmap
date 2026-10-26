<?php

use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ShowChangePasswordFormController;
use App\Http\Controllers\Auth\ShowSignInFormController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Garmin\CancelGarminImportController;
use App\Http\Controllers\Garmin\ConfirmGarminImportController;
use App\Http\Controllers\Garmin\ShowGarminImportFormController;
use App\Http\Controllers\Garmin\ShowGarminImportReviewController;
use App\Http\Controllers\Garmin\ShowGarminImportSummaryController;
use App\Http\Controllers\Garmin\UploadGarminCsvController;
use App\Http\Controllers\SignOutController;
use Illuminate\Support\Facades\Route;

/*
 * Sign-in is POSTed to a throttle that lives in the SignInOwner action, not a
 * route limiter: the key is username + IP, which a route limiter cannot see.
 * The gate that puts every other route behind the session is wired separately.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/login', ShowSignInFormController::class)->name('login');
    Route::post('/login', SignInController::class)->name('login.store');
});

/*
 * The first-password page is the one place a signed-in owner with no password
 * can be, and sign-out is the only other way out of it; both sit behind `auth`
 * alone, never behind `password.set`, or they would redirect to themselves.
 *
 * Sign out is POST only, so a cross-site link or a prefetch cannot sign the
 * owner out; CSRF comes from the web group. It has no throttle: the route does
 * no work worth limiting.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('/password/change', ShowChangePasswordFormController::class)->name('password.change');
    Route::put('/password/change', ChangePasswordController::class)->name('password.update');
    Route::post('/logout', SignOutController::class)->name('logout');
});

/*
 * Everything else is the owner's: a session (`auth`) whose password has been
 * set (`password.set`). /up, the health route, lives outside the web group and
 * /login is the guest group above, so these are the only other routes there are.
 * A route added later without this group is a gate bypass — RouteAccessTest
 * walks the route table to catch it.
 */
Route::middleware(['auth', 'password.set'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    /*
     * The Garmin CSV import imports into the signed-in owner, and is the only way a run enters. What
     * guards it besides the session: CSRF from the web group, the 5 MB rule in
     * the Form Request, the garmin-import throttle (per user) on upload, review
     * and confirm (the review re-plans the upload against the stored runs: work
     * that could otherwise be repeated without limit), and the session binding —
     * no route takes an import or an athlete id, so a request only reaches the
     * pending upload in its own session.
     */
    Route::prefix('import/garmin')
        ->name('garmin.import.')
        ->group(function (): void {
            Route::get('/', ShowGarminImportFormController::class)->name('create');
            Route::post('/', UploadGarminCsvController::class)->middleware('throttle:garmin-import')->name('store');
            Route::get('/review', ShowGarminImportReviewController::class)->middleware('throttle:garmin-import')->name('review');
            Route::post('/confirm', ConfirmGarminImportController::class)->middleware('throttle:garmin-import')->name('confirm');
            Route::post('/cancel', CancelGarminImportController::class)->name('cancel');
            Route::get('/summary', ShowGarminImportSummaryController::class)->name('summary');
        });
});
