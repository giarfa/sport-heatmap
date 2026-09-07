<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;

abstract class TestCase extends BaseTestCase
{
    /**
     * Put athletes on the allowlist for the duration of one test.
     *
     * The allowlist is re-checked on every authenticated request, so a test that
     * signs an athlete in has to say they are allowed in — leaving it implicit is
     * how the guard silently stops being exercised.
     */
    protected function allowlistAthletes(User|string ...$athletes): void
    {
        Config::set('strava.allowed_athlete_ids', array_map(
            static fn (User|string $athlete): string => $athlete instanceof User
                ? (string) $athlete->strava_athlete_id
                : $athlete,
            $athletes,
        ));
    }

    /**
     * Create a connected athlete who is on the allowlist.
     *
     * @param  array<string, mixed>  $state
     */
    protected function allowlistedAthlete(array $state = [], string $factoryState = 'stravaConnected'): User
    {
        /** @var User $user */
        $user = User::factory()->{$factoryState}()->create($state);

        $this->allowlistAthletes($user);

        return $user;
    }
}
