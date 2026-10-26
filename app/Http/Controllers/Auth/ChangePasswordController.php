<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ChangePasswordController extends Controller
{
    /**
     * Store the owner's first password and continue to the dashboard.
     */
    public function __invoke(ChangePasswordRequest $request): RedirectResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        /*
         * Checked here and not only on the form page: a password that already
         * exists must never be replaced by a request that skips the page. The
         * change is the forced first one only (FR-021).
         */
        if ($owner->password !== null) {
            return redirect()->route('dashboard');
        }

        // The `hashed` cast on User::password hashes the plain value on assignment.
        $owner->password = $request->validated('password');
        $owner->save();

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
