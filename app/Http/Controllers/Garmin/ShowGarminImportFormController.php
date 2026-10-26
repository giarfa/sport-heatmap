<?php

namespace App\Http\Controllers\Garmin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ShowGarminImportFormController extends Controller
{
    /**
     * The upload form, for the signed-in owner.
     */
    public function __invoke(): View
    {
        return view('garmin.create');
    }
}
