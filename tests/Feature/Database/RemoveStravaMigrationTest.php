<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The irreversible migration of US-021: it deletes every Strava run, drops the
 * Strava schema, and flushes the cache. Each test starts from the schema as it
 * was before the migration, arranges rows with the query builder, then runs it.
 */
class RemoveStravaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_26_210040_remove_strava_from_schema.php';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        $this->userId = DB::table('users')->insertGetId([
            'name' => 'Athlete',
            'email' => 'athlete@example.test',
            'strava_athlete_id' => '4242',
            'strava_access_token' => 'ciphertext-a',
            'strava_refresh_token' => 'ciphertext-b',
            'strava_token_expires_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_deletes_every_strava_run_and_keeps_every_garmin_run_with_all_its_values(): void
    {
        $this->insertActivity('strava', 111, '2026-10-01 07:00:00');
        $this->insertActivity('strava', 112, '2026-10-02 07:00:00');
        $new = $this->insertActivity('garmin_csv', null, '2026-10-03 07:00:00', ['name' => 'Verona Corsa', 'average_heart_rate' => 148]);
        $twin = $this->insertActivity('garmin_csv', 113, '2026-10-04 07:00:00', ['name' => 'Paese Corsa', 'total_ascent_meters' => 45]);
        $before = DB::table('activities')->whereIn('id', [$new, $twin])->get()->map(fn (object $row): array => (array) $row)->all();

        $this->migrateUp();

        $this->assertSame(2, DB::table('activities')->count());

        $after = DB::table('activities')->whereIn('id', [$new, $twin])->get()->map(fn (object $row): array => (array) $row)->all();

        foreach ($before as $index => $row) {
            unset($row['strava_activity_id'], $row['source']);

            $this->assertSame($row, $after[$index], 'A garmin_csv run keeps every value it had.');
        }
    }

    public function test_it_drops_the_strava_columns_the_sync_table_and_the_source_column_and_keeps_the_user(): void
    {
        DB::table('sync_runs')->insert(['started_at' => now(), 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);

        $this->migrateUp();

        foreach (['strava_athlete_id', 'strava_access_token', 'strava_refresh_token', 'strava_token_expires_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), "users.{$column} must be gone.");
        }

        $this->assertFalse(Schema::hasTable('sync_runs'));
        $this->assertFalse(Schema::hasColumn('activities', 'strava_activity_id'));
        $this->assertFalse(Schema::hasColumn('activities', 'source'));
        $this->assertSame('athlete@example.test', DB::table('users')->where('id', $this->userId)->value('email'));
    }

    public function test_a_grid_cached_before_the_migration_is_not_served_after_it(): void
    {
        Cache::put('heatmap:grid:v5:1:g0:2026-05-18:2026-09-05', 'a grid that still shows a deleted run', 3_600);
        Cache::put('garmin.pending', 'a remembered plan that names a deleted run', 3_600);

        $this->migrateUp();

        $this->assertFalse(Cache::has('heatmap:grid:v5:1:g0:2026-05-18:2026-09-05'));
        $this->assertFalse(Cache::has('garmin.pending'));
    }

    public function test_down_restores_the_schema_only_and_never_the_deleted_rows(): void
    {
        $this->insertActivity('strava', 111, '2026-10-01 07:00:00');
        $this->insertActivity('garmin_csv', null, '2026-10-03 07:00:00');

        $this->migrateUp();
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        foreach (['strava_athlete_id', 'strava_access_token', 'strava_refresh_token', 'strava_token_expires_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "users.{$column} must be back.");
        }

        $this->assertTrue(Schema::hasTable('sync_runs'));
        $this->assertTrue(Schema::hasColumn('activities', 'strava_activity_id'));
        $this->assertTrue(Schema::hasColumn('activities', 'source'));
        $this->assertSame(0, DB::table('sync_runs')->count());
        $this->assertSame(0, DB::table('activities')->where('source', 'strava')->count(), 'No Strava run comes back.');
        $this->assertSame(1, DB::table('activities')->count());
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('strava_athlete_id'), 'No token comes back.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertActivity(string $source, ?int $stravaId, string $start, array $attributes = []): int
    {
        return DB::table('activities')->insertGetId([
            'strava_activity_id' => $stravaId,
            'user_id' => $this->userId,
            'sport_type' => 'Run',
            'name' => 'Morning Run',
            'distance_meters' => 8_290,
            'moving_time_seconds' => 3_263,
            'start_date_local' => $start,
            'activity_date' => substr($start, 0, 10),
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    private function migrateUp(): void
    {
        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);
    }
}
