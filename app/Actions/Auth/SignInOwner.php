<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Decides whether a username and password may start a session for the owner.
 *
 * Returns the owner on success and null on any credential failure; the caller
 * shows the one generic message. Throttled attempts raise a validation error.
 */
final readonly class SignInOwner
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * A valid bcrypt hash of a random string nobody knows. It is only ever
     * checked against, never matched.
     */
    private const DUMMY_HASH = '$2y$12$3j8oMs1/0fP90X4h.AmkdettnEOBJRE.GtdwwYM1yChq3/PzkTtpy';

    /**
     * @throws ValidationException when this username and IP are over the attempt limit
     */
    public function __invoke(string $username, ?string $password, string $ip): ?User
    {
        $key = Str::transliterate(Str::lower($username)).'|'.$ip;

        /*
         * The throttle runs before any lookup or hash check: a refused attempt
         * must cost the attacker an attempt and cost us no credential work, and
         * it must not reveal whether the username exists.
         */
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'username' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ])->redirectTo(route('login'));
        }

        $owner = $this->findOwner($username);

        if ($owner !== null && $this->acceptsPassword($owner, $password)) {
            RateLimiter::clear($key);

            return $owner;
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return null;
    }

    private function findOwner(string $username): ?User
    {
        /*
         * Only the one fixed username is ever looked up: no other value can
         * name an account, so a request cannot probe or sign in as anyone else.
         */
        if (! hash_equals(User::OWNER_USERNAME, $username)) {
            $this->spendHashCheck();

            return null;
        }

        return User::query()->where('username', User::OWNER_USERNAME)->first();
    }

    private function acceptsPassword(User $owner, ?string $password): bool
    {
        /*
         * Hand-written instead of Auth::attempt: the Eloquent provider rejects a
         * null stored hash, and ConvertEmptyStringsToNull turns the empty
         * password of the first sign-in into null before it gets here. A null
         * stored password (first sign-in armed) accepts only an empty input;
         * a stored hash accepts only a non-empty input that matches it.
         */
        if ($owner->password === null) {
            return $password === null || $password === '';
        }

        if ($password === null || $password === '') {
            $this->spendHashCheck();

            return false;
        }

        return Hash::check($password, $owner->password);
    }

    /**
     * Burn one hash check, so a wrong username answers in about the time a
     * wrong password does and the response time does not name the username.
     */
    private function spendHashCheck(): void
    {
        Hash::check('not-a-password', self::DUMMY_HASH);
    }
}
