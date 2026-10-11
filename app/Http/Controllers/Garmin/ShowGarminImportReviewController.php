<?php

namespace App\Http\Controllers\Garmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Garmin\PendingGarminImport;
use App\Support\Garmin\PlanGarminImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShowGarminImportReviewController extends Controller
{
    /**
     * Plan the pending upload against the stored activities as they are now,
     * and show every conflict side by side. Reads only.
     */
    public function __invoke(Request $request, PendingGarminImport $pendingImport, PlanGarminImport $planGarminImport): View|RedirectResponse
    {
        $parsed = $pendingImport->parsed();
        $token = $pendingImport->token();

        if ($parsed === null || $token === null) {
            return redirect()->route('garmin.import.create')->with('status', 'Upload a Garmin CSV to review an import.');
        }

        /** @var User $owner */
        $owner = $request->user();

        $plan = $planGarminImport($owner, $parsed->rows);

        return view('garmin.review', [
            'parsed' => $parsed,
            'plan' => $plan,
            'token' => $token,
        ]);
    }
}
