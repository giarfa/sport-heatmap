<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_no_strava_column(): void
    {
        foreach ([
            'strava_athlete_id',
            'strava_access_token',
            'strava_refresh_token',
            'strava_token_expires_at',
        ] as $column) {
            $this->assertFalse(
                Schema::hasColumn('users', $column),
                "users.{$column} must be gone.",
            );
        }
    }

    public function test_users_password_is_nullable(): void
    {
        $this->assertTrue($this->columnIsNullable('users', 'password'));
    }

    public function test_users_username_is_nullable_and_unique(): void
    {
        $this->assertTrue($this->columnIsNullable('users', 'username'));
        $this->assertTrue(
            $this->hasIndexOn('users', ['username'], unique: true),
            'users.username must carry a unique index.',
        );
    }

    public function test_activities_table_has_every_required_column_with_the_expected_type(): void
    {
        $expected = [
            'user_id' => 'integer',
            'sport_type' => 'varchar',
            'name' => 'varchar',
            'distance_meters' => 'integer',
            'moving_time_seconds' => 'integer',
            'start_date_local' => 'datetime',
            'activity_date' => 'date',
        ];

        foreach ($expected as $column => $typeName) {
            $this->assertTrue(Schema::hasColumn('activities', $column), "activities.{$column} is missing.");
            $this->assertSame($typeName, $this->columnTypeName('activities', $column), "activities.{$column} has the wrong type.");
        }
    }

    public function test_activities_has_the_composite_user_and_date_index(): void
    {
        $this->assertTrue(
            $this->hasIndexOn('activities', ['user_id', 'activity_date']),
            'activities must index (user_id, activity_date) — it serves the only query the heatmap makes.',
        );
    }

    /**
     * Strava is removed, and the Garmin sync adds its own pair: where a run came from,
     * and which Garmin activity it is. Every row that existed is a CSV import.
     */
    public function test_activities_has_no_strava_id_and_a_source_defaulting_to_the_csv_import(): void
    {
        $this->assertFalse(Schema::hasColumn('activities', 'strava_activity_id'));
        $this->assertSame('varchar', $this->columnTypeName('activities', 'source'));
        $this->assertFalse($this->columnIsNullable('activities', 'source'));
        $this->assertStringContainsString('garmin_csv', (string) $this->columnDefault('activities', 'source'));
    }

    public function test_the_garmin_activity_id_is_a_nullable_unique_integer(): void
    {
        $this->assertSame('integer', $this->columnTypeName('activities', 'garmin_activity_id'));
        $this->assertTrue($this->columnIsNullable('activities', 'garmin_activity_id'));
        $this->assertNull($this->columnDefault('activities', 'garmin_activity_id'));
        $this->assertTrue(
            $this->hasIndexOn('activities', ['garmin_activity_id'], unique: true),
            'activities.garmin_activity_id must carry a unique index: it is what keeps a second sync from duplicating a run.',
        );
    }

    public function test_garmin_syncs_records_each_attempt_and_the_status_index_exists(): void
    {
        foreach ([
            'trigger', 'started_at', 'finished_at', 'outcome', 'failure_cause', 'full_history',
            'new_count', 'updated_count', 'linked_count', 'ignored_count', 'future_dated_count', 'invalid_count',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('garmin_syncs', $column), "garmin_syncs.{$column} is missing.");
        }

        $this->assertTrue($this->columnIsNullable('garmin_syncs', 'finished_at'));
        $this->assertTrue($this->columnIsNullable('garmin_syncs', 'failure_cause'));
        $this->assertTrue($this->hasIndexOn('garmin_syncs', ['outcome', 'finished_at']));
    }

    /**
     * The failure is a cause from a closed list. A column able to hold a key, a token,
     * a URL or a response body would give a secret somewhere to be stored.
     */
    public function test_garmin_syncs_has_no_column_able_to_hold_a_secret(): void
    {
        $allowed = [
            'id', 'trigger', 'started_at', 'finished_at', 'outcome', 'failure_cause', 'full_history',
            'new_count', 'updated_count', 'linked_count', 'ignored_count', 'future_dated_count', 'invalid_count',
            'created_at', 'updated_at',
        ];

        $columns = Schema::getColumnListing('garmin_syncs');

        $this->assertEqualsCanonicalizing($allowed, $columns);
        $this->assertSame('varchar', $this->columnTypeName('garmin_syncs', 'failure_cause'));
    }

    /**
     * NULL means "not recorded" and renders as an em dash; a default of 0 would read as
     * a measurement, so the three run metrics must be nullable with no default.
     */
    public function test_activities_run_metrics_are_nullable_integers_with_no_default(): void
    {
        foreach ([
            'average_pace_seconds_per_km',
            'total_ascent_meters',
            'average_heart_rate',
        ] as $column) {
            $this->assertSame('integer', $this->columnTypeName('activities', $column), "activities.{$column} has the wrong type.");
            $this->assertTrue($this->columnIsNullable('activities', $column), "activities.{$column} must be nullable.");
            $this->assertNull($this->columnDefault('activities', $column), "activities.{$column} must have no default.");
        }
    }

    /**
     * Start and end positions are never stored from either Garmin source.
     */
    public function test_activities_has_no_latitude_or_longitude_column(): void
    {
        foreach (Schema::getColumnListing('activities') as $column) {
            $this->assertDoesNotMatchRegularExpression('/(^|_)(lat|latitude|lon|lng|longitude)(_|$)/i', $column, "activities.{$column} looks like a coordinate.");
        }
    }

    public function test_there_is_no_sync_runs_table(): void
    {
        $this->assertFalse(Schema::hasTable('sync_runs'));
    }

    /**
     * The PRD explicitly rejects a pre-aggregated mileage table: the grid aggregates
     * on read. Asserted here so no later spec quietly reintroduces it.
     */
    public function test_there_is_no_daily_mileage_aggregate_table(): void
    {
        $this->assertFalse(Schema::hasTable('daily_mileage'));
    }

    private function columnTypeName(string $table, string $column): ?string
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return $definition['type_name'];
            }
        }

        return null;
    }

    private function columnDefault(string $table, string $column): ?string
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return $definition['default'];
            }
        }

        return null;
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return (bool) $definition['nullable'];
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function hasIndexOn(string $table, array $columns, bool $unique = false): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns && (! $unique || $index['unique'])) {
                return true;
            }
        }

        return false;
    }
}
