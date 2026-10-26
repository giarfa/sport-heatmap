<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\TrainingBlockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A freshly migrated database already holds the owner the username migration
 * creates; seeding the demo athlete must reuse that row, not collide with it.
 */
class SeederMeetsMigrationOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $clearsMigrationOwner = false;

    public function test_the_demo_athlete_becomes_the_owner_the_migration_created(): void
    {
        $this->assertSame(User::OWNER_USERNAME, User::sole()->username);

        $this->seed(TrainingBlockSeeder::class);

        $owner = User::sole();
        $this->assertSame(User::OWNER_USERNAME, $owner->username);
        $this->assertSame('Demo Athlete', $owner->name);
        $this->assertNull($owner->password);
        $this->assertSame('10000001', $owner->strava_athlete_id);
        $this->assertGreaterThan(0, $owner->activities()->count());
    }
}
