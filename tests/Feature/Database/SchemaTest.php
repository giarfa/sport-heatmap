<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_the_strava_identity_and_token_columns(): void
    {
        foreach ([
            'strava_athlete_id',
            'strava_access_token',
            'strava_refresh_token',
            'strava_token_expires_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                "users.{$column} is missing.",
            );
        }
    }

    public function test_strava_token_columns_are_text_so_ciphertext_is_never_truncated(): void
    {
        foreach (['strava_access_token', 'strava_refresh_token'] as $column) {
            $this->assertSame(
                'text',
                $this->columnTypeName('users', $column),
                "users.{$column} must be TEXT: the encrypted cast produces ciphertext of unpredictable length.",
            );
        }
    }

    public function test_users_password_is_nullable(): void
    {
        $this->assertTrue($this->columnIsNullable('users', 'password'));
    }

    public function test_strava_athlete_id_is_unique(): void
    {
        $this->assertTrue(
            $this->hasIndexOn('users', ['strava_athlete_id'], unique: true),
            'users.strava_athlete_id must carry a unique index.',
        );
    }

    public function test_activities_table_has_every_required_column_with_the_expected_type(): void
    {
        $expected = [
            'strava_activity_id' => 'integer',
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

    public function test_activities_strava_activity_id_is_unique(): void
    {
        $this->assertTrue($this->hasIndexOn('activities', ['strava_activity_id'], unique: true));
    }

    public function test_sync_runs_table_has_every_required_column(): void
    {
        foreach ([
            'started_at',
            'finished_at',
            'status',
            'activities_upserted',
            'activities_deleted',
            'error_message',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('sync_runs', $column), "sync_runs.{$column} is missing.");
        }
    }

    public function test_sync_runs_finished_at_is_nullable_for_runs_in_flight(): void
    {
        $this->assertTrue($this->columnIsNullable('sync_runs', 'finished_at'));
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
