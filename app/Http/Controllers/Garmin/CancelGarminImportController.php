<?php

namespace App\Http\Controllers\Garmin;

use App\Http\Controllers\Controller;
use App\Support\Garmin\PendingGarminImport;
use Illuminate\Http\RedirectResponse;

class CancelGarminImportController extends Controller
{
    /**
     * Drop the pending upload. Nothing was ever written, so there is nothing to
     * undo.
     */
    public function __invoke(PendingGarminImport $pendingImport): RedirectResponse
    {
        $pendingImport->forget();

        return redirect()->route('dashboard')->with('status', 'Import cancelled. Nothing was written.');
    }
}
