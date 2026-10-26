<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class ShowSignInFormController extends Controller
{
    /**
     * Render the owner sign-in page.
     */
    public function __invoke(): View
    {
        return view('auth.login');
    }
}
