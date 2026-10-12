<?php

namespace App\Http\Controllers\Garmin;

use App\Exceptions\Garmin\GarminCsvRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Garmin\UploadGarminCsvRequest;
use App\Support\Garmin\ParseGarminCsv;
use App\Support\Garmin\PendingGarminImport;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

class UploadGarminCsvController extends Controller
{
    /**
     * Parse the upload and park the result in the visitor's session for review.
     * Nothing reaches the database here.
     */
    public function __invoke(
        UploadGarminCsvRequest $request,
        ParseGarminCsv $parseGarminCsv,
        PendingGarminImport $pendingImport,
    ): RedirectResponse {
        /** @var UploadedFile $file */
        $file = $request->file('csv');

        /*
         * Read straight from PHP's temporary upload, never store()d: PHP deletes
         * it when the request ends, so the raw export — the owner's heart rate,
         * cadence and locations included — is never persisted beyond the request.
         */
        try {
            $parsed = $parseGarminCsv((string) $file->getRealPath(), HeatmapWindow::current(), now());
        } catch (GarminCsvRejectedException $exception) {
            return redirect()->route('garmin.import.create')->withErrors(['csv' => $exception->getMessage()]);
        }

        $pendingImport->put($parsed);

        return redirect()->route('garmin.import.review');
    }
}
