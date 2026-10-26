<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShowChangePasswordFormController extends Controller
{
    /**
     * Render the first-password page, or go to the dashboard once a password exists.
     *
     * The page only exists for the forced first change: a voluntary change
     * afterwards is out of scope (FR-021), so it closes once a password is set.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        if ($owner->password !== null) {
            return redirect()->route('dashboard');
        }

        return view('auth.change-password');
    }
}
