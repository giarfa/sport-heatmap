<?php

namespace Tests\Feature\Database;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The additive migration of US-022: every run stored before it is a CSV import, and it
 * rolls back one step cleanly.
 */
class GarminSyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_14_123827_add_garmin_sync_to_activities_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
    }

    public function test_rows_stored_before_it_read_back_as_csv_imports_with_no_garmin_id(): void
    {
        $this->insertActivity('2026-10-01 07:00:00');

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);

        $row = DB::table('activities')->sole();

        $this->assertSame('garmin_csv', $row->source);
        $this->assertNull($row->garmin_activity_id);
    }

    public function test_it_rolls_back_one_step_and_keeps_the_runs(): void
    {
        $this->insertActivity('2026-10-01 07:00:00');
        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);
        DB::table('activities')->update(['garmin_activity_id' => 12_345_678_901]);

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        $this->assertFalse(Schema::hasColumn('activities', 'source'));
        $this->assertFalse(Schema::hasColumn('activities', 'garmin_activity_id'));
        $this->assertSame(1, DB::table('activities')->count());
    }

    public function test_a_second_run_cannot_take_an_existing_garmin_id(): void
    {
        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);
        $first = $this->insertActivity('2026-10-01 07:00:00');
        $second = $this->insertActivity('2026-10-02 07:00:00');

        DB::table('activities')->where('id', $first)->update(['garmin_activity_id' => 12_345_678_901]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('activities')->where('id', $second)->update(['garmin_activity_id' => 12_345_678_901]);
    }

    private function insertActivity(string $start): int
    {
        $userId = DB::table('users')->value('id') ?? DB::table('users')->insertGetId([
            'name' => 'Athlete',
            'email' => 'athlete@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('activities')->insertGetId([
            'user_id' => $userId,
            'sport_type' => 'Run',
            'name' => 'Morning Run',
            'distance_meters' => 8_290,
            'moving_time_seconds' => 3_263,
            'start_date_local' => $start,
            'activity_date' => substr($start, 0, 10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
