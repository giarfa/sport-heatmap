<?php

namespace App\Http\Controllers\Garmin;

use App\Actions\Garmin\ApplyGarminImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Garmin\ConfirmGarminImportRequest;
use App\Models\User;
use App\Support\Garmin\PendingGarminImport;
use App\Support\Garmin\PlanGarminImport;
use App\Support\Garmin\ResolveGarminImportTarget;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class ConfirmGarminImportController extends Controller
{
    /**
     * Apply the reviewed import, provided the visitor is still answering the
     * review they were shown, for the athlete it was planned against.
     */
    public function __invoke(
        ConfirmGarminImportRequest $request,
        PendingGarminImport $pendingImport,
        PlanGarminImport $planGarminImport,
        ApplyGarminImport $applyGarminImport,
        ResolveGarminImportTarget $resolveImportTarget,
    ): RedirectResponse {
        $parsed = $pendingImport->parsed();
        $token = $pendingImport->token();

        if ($parsed === null || $token === null) {
            return redirect()->route('garmin.import.create')->with('status', 'Upload a Garmin CSV to review an import.');
        }

        /*
         * One confirm at a time for the whole instance, not per athlete: a
         * guest's first import has no athlete to key on until the CSV-only
         * athlete exists, and this is a single-athlete instance, so the
         * serialisation costs nothing. A double-clicked "Confirm import", or
         * two guests confirming a first import at once, therefore run one
         * after the other; the second re-plans after the first committed —
         * against the athlete it just created, or with its runs already
         * imported — fails the fingerprint and writes nothing.
         *
         * The target is resolved inside the lock for the same reason: read
         * before it, the second request would still see "no athlete" and
         * plan against a target that no longer exists.
         */
        try {
            return Cache::lock('garmin-import:confirm', 30)->block(10, function () use ($request, $parsed, $token, $pendingImport, $planGarminImport, $applyGarminImport, $resolveImportTarget): RedirectResponse {
                /** @var User|null $signedIn */
                $signedIn = $request->user();
                $athlete = $resolveImportTarget($signedIn);

                /*
                 * The plan is recomputed from the session rows against the database
                 * as it is now, never taken from the form. A sync or a second tab
                 * may have changed the stored runs since the review rendered; if
                 * the conflicts no longer pair the same rows with the same stored
                 * runs, the visitor's answers could land on runs they never saw, so
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
             * The lock is instance-wide and guests can reach it, so another
             * visitor's large confirm can hold it past the wait. Nothing was
             * written and the upload is still pending, so the visitor goes back
             * to the review rather than getting a 500.
             */
            return redirect()->route('garmin.import.review')
                ->with('garmin_import.stale', 'Another import is being saved — confirm again in a moment.');
        }
    }
}
