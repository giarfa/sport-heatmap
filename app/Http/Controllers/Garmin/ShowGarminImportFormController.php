<?php

namespace App\Http\Controllers\Garmin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ShowGarminImportFormController extends Controller
{
    /**
     * The upload form. Owner-only: the route sits behind auth and strava.fresh.
     */
    public function __invoke(): View
    {
        return view('garmin.create');
    }
}
