<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Whether a RefreshDatabase test starts with no user. Set it to false to meet
     * the owner the username migration creates on an empty database.
     */
    protected bool $clearsMigrationOwner = true;

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
        if ($this->clearsMigrationOwner && in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            DB::table('users')->delete();
        }
    }

    /**
     * Sign the owner in: `giarfa` with a password already chosen.
     *
     * @param  array<string, mixed>  $state
     */
    protected function signInOwner(array $state = []): User
    {
        /** @var User $owner */
        $owner = User::factory()->ownerWithPassword()->create($state);

        $this->actingAs($owner);

        return $owner;
    }
}
