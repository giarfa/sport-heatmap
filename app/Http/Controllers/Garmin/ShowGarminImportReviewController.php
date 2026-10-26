<?php

namespace App\Http\Controllers\Garmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Garmin\PendingGarminImport;
use App\Support\Garmin\RememberedGarminImportPlan;
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
    ): View|RedirectResponse {
        $parsed = $pendingImport->parsed();
        $token = $pendingImport->token();

        if ($parsed === null || $token === null) {
            return redirect()->route('garmin.import.create')->with('status', 'Upload a Garmin CSV to review an import.');
        }

        /** @var User $owner */
        $owner = $request->user();

        return view('garmin.review', [
            'parsed' => $parsed,
            'plan' => $plan($owner, $parsed),
            'token' => $token,
        ]);
    }
}
