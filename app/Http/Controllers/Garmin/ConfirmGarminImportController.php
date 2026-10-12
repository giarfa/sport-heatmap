<?php

namespace App\Http\Controllers\Garmin;

use App\Actions\Garmin\ApplyGarminImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Garmin\ConfirmGarminImportRequest;
use App\Models\User;
use App\Support\Garmin\PendingGarminImport;
use App\Support\Garmin\PlanGarminImport;
use App\Support\Garmin\ResolveGarminImportTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class ConfirmGarminImportController extends Controller
{
    /**
     * Apply the reviewed import, provided the owner is still answering the
     * review they were shown.
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

        /** @var User $owner */
        $owner = $request->user();

        /*
         * One confirm per owner at a time. A double-clicked "Confirm import"
         * sends two requests that both carry the pending import in their
         * session; serialised, the second re-plans after the first committed,
         * finds its new runs already imported, fails the fingerprint and
         * writes nothing.
         */
        return Cache::lock("garmin-import:confirm:{$owner->id}", 30)->block(10, function () use ($request, $owner, $parsed, $token, $pendingImport, $planGarminImport, $applyGarminImport, $resolveImportTarget): RedirectResponse {
            $athlete = $resolveImportTarget($owner);

            /*
             * The plan is recomputed from the session rows against the database
             * as it is now, never taken from the form. A sync or a second tab
             * may have changed the stored runs since the review rendered; if the
             * conflicts no longer pair the same rows with the same stored runs,
             * the owner's answers could land on runs they never saw, so nothing
             * is written. The token ties the form to this upload: a review page
             * left open across a newer upload cannot confirm it.
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
    }
}
