<?php

namespace Tests\Feature\Database;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsernameMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_26_201636_add_username_to_users_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Start from the schema as it was before this migration, with the users
        // each test arranges; migrateUp() is what the test then runs.
        $this->migrateDown();
    }

    public function test_the_allowlisted_connected_athlete_wins_over_a_lower_id_athlete_with_runs(): void
    {
        $withRuns = $this->insertUser(['strava_athlete_id' => '111']);
        $this->insertActivity($withRuns);
        $allowlisted = $this->insertUser(['strava_athlete_id' => '222']);
        Config::set('strava.allowed_athlete_ids', ['999', '222']);

        $this->migrateUp();

        $this->assertSame([$allowlisted], $this->ownerIds());
    }

    public function test_without_an_allowlist_match_the_lowest_id_user_with_runs_is_the_owner(): void
    {
        $this->insertUser();
        $first = $this->insertUser();
        $second = $this->insertUser();
        $this->insertActivity($second);
        $this->insertActivity($first);
        Config::set('strava.allowed_athlete_ids', ['999']);

        $this->migrateUp();

        $this->assertSame([$first], $this->ownerIds());
    }

    public function test_without_any_run_the_lowest_id_user_is_the_owner(): void
    {
        $first = $this->insertUser();
        $this->insertUser();

        $this->migrateUp();

        $this->assertSame([$first], $this->ownerIds());
    }

    public function test_an_empty_database_gets_the_owner_with_no_password(): void
    {
        $this->migrateUp();

        $owner = DB::table('users')->sole();

        $this->assertSame('giarfa', $owner->username);
        $this->assertNull($owner->password);
    }

    public function test_the_owner_keeps_runs_and_every_other_column_but_loses_the_password(): void
    {
        $ownerId = $this->insertUser([
            'strava_athlete_id' => '222',
            'strava_access_token' => 'ciphertext-access',
            'strava_refresh_token' => 'ciphertext-refresh',
            'strava_token_expires_at' => '2026-10-26 12:00:00',
            'password' => 'a previous password hash',
            'updated_at' => '2026-10-01 08:00:00',
        ]);
        $this->insertActivity($ownerId);
        $this->insertActivity($ownerId);
        $this->insertActivity($ownerId);
        $before = DB::table('users')->where('id', $ownerId)->first();
        Config::set('strava.allowed_athlete_ids', ['222']);

        $this->migrateUp();

        $after = DB::table('users')->where('id', $ownerId)->first();

        $this->assertSame('giarfa', $after->username);
        $this->assertNull($after->password);
        $this->assertSame(3, DB::table('activities')->where('user_id', $ownerId)->count());

        foreach (['name', 'email', 'strava_athlete_id', 'strava_access_token', 'strava_refresh_token', 'strava_token_expires_at', 'updated_at'] as $column) {
            $this->assertSame($before->{$column}, $after->{$column}, "users.{$column} changed.");
        }
    }

    public function test_only_one_row_carries_a_username(): void
    {
        $this->insertUser();
        $this->insertUser();
        $this->insertUser();

        $this->migrateUp();

        $this->assertCount(1, $this->ownerIds());
    }

    public function test_down_drops_the_column(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'username'));

        $this->migrateUp();
        $this->assertTrue(Schema::hasColumn('users', 'username'));

        $this->migrateDown();
        $this->assertFalse(Schema::hasColumn('users', 'username'));
    }

    /**
     * Insert a user without a username (the column does not exist before up()).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertUser(array $attributes = []): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Athlete '.Str::random(6),
            'email' => Str::random(10).'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    private function migrateUp(): void
    {
        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);
    }

    private function migrateDown(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
    }

    private function insertActivity(int $userId): void
    {
        Activity::factory()->create(['user_id' => $userId]);
    }

    /**
     * @return array<int, int>
     */
    private function ownerIds(): array
    {
        return DB::table('users')->where('username', 'giarfa')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
