<?php

namespace App\Support\Garmin;

use App\Models\User;

/**
 * The account a sync writes into: the one owner of this instance. The command has no
 * session to read it from, so it is found the way the sign-in finds it, by username.
 */
final readonly class ResolveSyncOwner
{
    public function __invoke(): ?User
    {
        return User::query()->where('username', User::OWNER_USERNAME)->first();
    }
}
