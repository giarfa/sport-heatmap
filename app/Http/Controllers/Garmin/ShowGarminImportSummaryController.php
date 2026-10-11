<?php

namespace App\Http\Controllers\Garmin;

use App\Data\Garmin\GarminImportSummary;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ShowGarminImportSummaryController extends Controller
{
    /**
     * What the confirmed import did. The summary arrives as flash data, so a
     * reload — or a visit with no import behind it — goes back to the map.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $flashed = $request->session()->get('garmin_import.summary');

        try {
            $summary = is_array($flashed) ? GarminImportSummary::fromSessionArray($flashed) : null;
        } catch (Throwable) {
            $summary = null;
        }

        if ($summary === null) {
            return redirect()->route('dashboard');
        }

        return view('garmin.summary', ['summary' => $summary]);
    }
}
