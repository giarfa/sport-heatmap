<?php

namespace App\Http\Controllers\Garmin;

use App\Http\Controllers\Controller;
use App\Support\Garmin\PendingGarminImport;
use App\Support\Garmin\RememberedGarminImportPlan;
use App\Support\Garmin\ResolveGarminImportTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShowGarminImportReviewController extends Controller
{
    /**
     * Plan the pending upload against the stored activities as they are now,
     * and show every conflict side by side. Reads only. The plan is made once
     * and reused on a reload while the stored runs on the upload's dates are
     * unchanged; a stored run that changed, or a new upload, plans again.
     */
    public function __invoke(
        Request $request,
        PendingGarminImport $pendingImport,
        RememberedGarminImportPlan $plan,
        ResolveGarminImportTarget $resolveImportTarget,
    ): View|RedirectResponse {
        $parsed = $pendingImport->parsed();
        $token = $pendingImport->token();

        if ($parsed === null || $token === null) {
            return redirect()->route('garmin.import.create')->with('status', 'Upload a Garmin CSV to review an import.');
        }

        $reviewPlan = $plan($resolveImportTarget($request->user()), $parsed);

        /*
         * A signed-in owner reviews their own athlete; a guest reviews the displayed athlete's
         * runs and must not enumerate them (name, distance, time, Strava link) by uploading
         * crafted rows that clash. Decided once here so the view never re-derives auth.
         */
        return view('garmin.review', [
            'parsed' => $parsed,
            'plan' => $reviewPlan,
            'token' => $token,
            'showsStoredRunDetails' => $request->user() !== null,
        ]);
    }
}
