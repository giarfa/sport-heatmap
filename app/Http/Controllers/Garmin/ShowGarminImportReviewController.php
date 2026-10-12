<?php

namespace App\Http\Controllers\Garmin;

use App\Http\Controllers\Controller;
use App\Support\Garmin\PendingGarminImport;
use App\Support\Garmin\PlanGarminImport;
use App\Support\Garmin\ResolveGarminImportTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShowGarminImportReviewController extends Controller
{
    /**
     * Plan the pending upload against the stored activities as they are now,
     * and show every conflict side by side. Reads only.
     */
    public function __invoke(
        Request $request,
        PendingGarminImport $pendingImport,
        PlanGarminImport $planGarminImport,
        ResolveGarminImportTarget $resolveImportTarget,
    ): View|RedirectResponse {
        $parsed = $pendingImport->parsed();
        $token = $pendingImport->token();

        if ($parsed === null || $token === null) {
            return redirect()->route('garmin.import.create')->with('status', 'Upload a Garmin CSV to review an import.');
        }

        $plan = $planGarminImport($resolveImportTarget($request->user()), $parsed->rows);

        /*
         * A signed-in owner reviews their own athlete; a guest reviews the displayed athlete's
         * runs and must not enumerate them (name, distance, time, Strava link) by uploading
         * crafted rows that clash. Decided once here so the view never re-derives auth.
         */
        return view('garmin.review', [
            'parsed' => $parsed,
            'plan' => $plan,
            'token' => $token,
            'showsStoredRunDetails' => $request->user() !== null,
        ]);
    }
}
