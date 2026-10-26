<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The username migration creates the giarfa owner on an empty database,
         * so every freshly migrated test database starts with one user. Tests
         * arrange their own users (and the unique username would collide with
         * the owner() factory states), so clear the row inside the test's
         * transaction. UsernameMigrationTest covers the migration itself.
         */
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            DB::table('users')->delete();
        }
    }

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
        $user = User::factory()->{$factoryState}()->create([
            // A signed-in athlete has chosen a password; a null one is forced to the change page.
            'password' => 'correct horse battery',
            ...$state,
        ]);

        $this->allowlistAthletes($user);

        return $user;
    }
}
