<?php

namespace App\Http\Controllers\Garmin;

use App\Actions\Garmin\ApplyGarminImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Garmin\ConfirmGarminImportRequest;
use App\Models\User;
use App\Support\Garmin\PendingGarminImport;
use App\Support\Garmin\PlanGarminImport;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class ConfirmGarminImportController extends Controller
{
    /**
     * Apply the reviewed import, provided the owner is still answering the
     * review they were shown, for the athlete it was planned against.
     */
    public function __invoke(
        ConfirmGarminImportRequest $request,
        PendingGarminImport $pendingImport,
        PlanGarminImport $planGarminImport,
        ApplyGarminImport $applyGarminImport,
    ): RedirectResponse {
        $parsed = $pendingImport->parsed();
        $token = $pendingImport->token();

        if ($parsed === null || $token === null) {
            return redirect()->route('garmin.import.create')->with('status', 'Upload a Garmin CSV to review an import.');
        }

        /*
         * One confirm at a time for the whole instance: this is a single-owner
         * instance, so the serialisation costs nothing. A double-clicked
         * "Confirm import", or the same review confirmed from two tabs, run one
         * after the other; the second re-plans after the first committed, with
         * its runs already imported, fails the fingerprint and writes nothing.
         */
        try {
            return Cache::lock('garmin-import:confirm', 30)->block(10, function () use ($request, $parsed, $token, $pendingImport, $planGarminImport, $applyGarminImport): RedirectResponse {
                /** @var User $athlete */
                $athlete = $request->user();

                /*
                 * Planned fresh, never through RememberedGarminImportPlan: the fingerprint
                 * has to compare against the database at write time, inside this lock.
                 *
                 * The plan is recomputed from the session rows against the database
                 * as it is now, never taken from the form. A second tab
                 * may have changed the stored runs since the review rendered; if
                 * the conflicts no longer pair the same rows with the same stored
                 * runs, the owner's answers could land on runs they never saw, so
                 * nothing is written. The fingerprint carries the target athlete
                 * too, so a target that changed since the review fails the same
                 * way. The token ties the form to this upload: a review page left
                 * open across a newer upload cannot confirm it.
                 */
                $plan = $planGarminImport($athlete, $parsed->rows);

                if (! hash_equals($token, (string) $request->validated('token'))
                    || ! hash_equals($plan->fingerprint(), (string) $request->validated('fingerprint'))) {
                    return redirect()->route('garmin.import.review')
                        ->with('garmin_import.stale', 'Your stored activities changed since this review — check the conflicts again.');
                }

                $resolutions = $request->resolutions();

                if (array_diff_key($plan->conflicts, $resolutions) !== []) {
                    return redirect()->route('garmin.import.review')
                        ->withErrors(['resolutions' => 'Choose Overwrite or Skip for every clash.']);
                }

                $summary = $applyGarminImport($athlete, $plan, $resolutions, $parsed);

                $pendingImport->forget();

                return redirect()->route('garmin.import.summary')->with('garmin_import.summary', $summary->toSessionArray());
            });
        } catch (LockTimeoutException) {
            /*
             * The lock is instance-wide, so another large confirm can hold it past
             * the wait. Nothing was written and the upload is still pending, so
             * the owner goes back to the review rather than getting a 500.
             */
            return redirect()->route('garmin.import.review')
                ->with('garmin_import.stale', 'Another import is being saved — confirm again in a moment.');
        }
    }
}
