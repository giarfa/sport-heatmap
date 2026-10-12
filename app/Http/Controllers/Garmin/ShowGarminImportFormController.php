<?php

namespace App\Http\Controllers\Garmin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ShowGarminImportFormController extends Controller
{
    /**
     * The upload form, open to every visitor (US-011).
     */
    public function __invoke(): View
    {
        return view('garmin.create');
    }
}
