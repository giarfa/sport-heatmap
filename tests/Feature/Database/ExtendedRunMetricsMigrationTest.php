<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The additive migration of US-023: 27 nullable extended-metric columns, no run touched,
 * and a rollback that drops only them.
 */
class ExtendedRunMetricsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_14_141912_add_extended_metrics_to_activities_table.php';

    /**
     * Every column the migration adds.
     *
     * @var array<int, string>
     */
    public const EXTENDED_COLUMNS = [
        'calories',
        'max_heart_rate',
        'aerobic_training_effect',
        'average_running_cadence',
        'max_running_cadence',
        'best_pace_seconds_per_km',
        'total_descent_meters',
        'average_stride_length_cm',
        'average_vertical_ratio_percent',
        'average_vertical_oscillation_cm',
        'average_ground_contact_time_ms',
        'average_ground_contact_balance_left_percent',
        'steps',
        'lap_count',
        'elapsed_time_seconds',
        'min_elevation_meters',
        'max_elevation_meters',
        'training_stress_score',
        'hr_zone_1_seconds',
        'hr_zone_2_seconds',
        'hr_zone_3_seconds',
        'hr_zone_4_seconds',
        'hr_zone_5_seconds',
        'fastest_1k_seconds',
        'fastest_mile_seconds',
        'fastest_5k_seconds',
        'location_name',
    ];

    public function test_it_adds_exactly_twenty_seven_nullable_columns_with_no_default(): void
    {
        $this->assertCount(27, self::EXTENDED_COLUMNS);

        $definitions = collect(Schema::getColumns('activities'))->keyBy('name');

        foreach (self::EXTENDED_COLUMNS as $column) {
            $this->assertTrue($definitions->has($column), "activities.{$column} is missing.");
            $this->assertTrue((bool) $definitions[$column]['nullable'], "activities.{$column} must be nullable.");
            $this->assertNull($definitions[$column]['default'], "activities.{$column} must have no default.");
        }
    }

    public function test_a_run_stored_before_it_keeps_every_value_and_reads_no_extended_metric(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
        $this->insertActivity();

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);

        $row = (array) DB::table('activities')->sole();

        $this->assertSame('Morning Run', $row['name']);
        $this->assertSame(8_290, $row['distance_meters']);
        $this->assertSame(3_263, $row['moving_time_seconds']);
        $this->assertSame(394, $row['average_pace_seconds_per_km']);
        $this->assertSame(41, $row['total_ascent_meters']);
        $this->assertSame(151, $row['average_heart_rate']);

        foreach (self::EXTENDED_COLUMNS as $column) {
            $this->assertNull($row[$column], "activities.{$column} must read null on a run stored before the migration.");
        }
    }

    public function test_it_rolls_back_dropping_only_its_columns_and_keeps_every_run(): void
    {
        $before = Schema::getColumnListing('activities');
        $this->insertActivity();
        DB::table('activities')->update(['calories' => 512, 'location_name' => 'Lazise']);

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        $after = Schema::getColumnListing('activities');

        $this->assertEqualsCanonicalizing(self::EXTENDED_COLUMNS, array_values(array_diff($before, $after)));
        $this->assertSame([], array_values(array_diff($after, $before)));
        $this->assertSame(1, DB::table('activities')->count());
        $this->assertSame('Morning Run', DB::table('activities')->value('name'));
        $this->assertSame(151, DB::table('activities')->value('average_heart_rate'));
    }

    private function insertActivity(): int
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
            'start_date_local' => '2026-10-01 07:00:00',
            'activity_date' => '2026-10-01',
            'average_pace_seconds_per_km' => 394,
            'total_ascent_meters' => 41,
            'average_heart_rate' => 151,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
