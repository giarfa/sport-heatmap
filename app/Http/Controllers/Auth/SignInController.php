<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SignInOwner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignInRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SignInController extends Controller
{
    /**
     * Sign the owner in, or send them back to the form with one generic error.
     */
    public function __invoke(SignInRequest $request, SignInOwner $signInOwner): RedirectResponse
    {
        $owner = $signInOwner(
            $request->string('username')->toString(),
            $request->input('password'),
            (string) $request->ip(),
        );

        if ($owner === null) {
            /*
             * One message for a wrong username and a wrong password, and only
             * the username is flashed back: the password is never echoed.
             */
            return redirect()->route('login')
                ->withErrors(['username' => 'These credentials do not match our records.'])
                ->withInput($request->only('username'));
        }

        // No remember-me: the session is the only way in, and it ends with the browser.
        Auth::login($owner, remember: false);

        $request->session()->regenerate();

        return redirect($this->intendedUrl($request->session()->pull('url.intended')));
    }

    /**
     * The page the owner first asked for, when it is on this site.
     */
    private function intendedUrl(mixed $intended): string
    {
        /*
         * redirect()->intended() would follow whatever URL the session holds,
         * and the generator passes an absolute URL through untouched — an
         * off-site address planted there would become an open redirect after a
         * successful sign-in. Only this host's own URLs are honoured.
         */
        if (is_string($intended)
            && ($intended === url('/') || Str::startsWith($intended, url('/').'/'))) {
            return $intended;
        }

        return route('dashboard');
    }
}
