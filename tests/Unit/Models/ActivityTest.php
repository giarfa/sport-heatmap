<?php

namespace Tests\Unit\Models;

use App\Enums\ActivitySource;
use App\Enums\SportType;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Database\ExtendedRunMetricsMigrationTest;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_casts_its_attributes(): void
    {
        $activity = Activity::factory()->create();

        $this->assertInstanceOf(SportType::class, $activity->sport_type);
        $this->assertInstanceOf(Carbon::class, $activity->activity_date);
        $this->assertInstanceOf(Carbon::class, $activity->start_date_local);
        $this->assertGreaterThan(0, $activity->distance_meters);
        $this->assertGreaterThan(0, $activity->moving_time_seconds);
    }

    public function test_the_activity_date_always_matches_the_local_start_date(): void
    {
        $activity = Activity::factory()->create();

        $this->assertSame(
            $activity->start_date_local->toDateString(),
            $activity->activity_date->toDateString(),
        );
    }

    public function test_the_factory_produces_a_plausible_running_pace(): void
    {
        $activity = Activity::factory()->create();

        $secondsPerKilometre = $activity->moving_time_seconds / ($activity->distance_meters / 1000);

        $this->assertGreaterThanOrEqual(240, $secondsPerKilometre);
        $this->assertLessThanOrEqual(400, $secondsPerKilometre);
    }

    public function test_the_factory_fills_realistic_run_metrics(): void
    {
        $activity = Activity::factory()->create()->refresh();

        $this->assertEqualsWithDelta(
            $activity->moving_time_seconds / ($activity->distance_meters / 1000),
            $activity->average_pace_seconds_per_km,
            1.0,
            'The stored pace must be the pace of the moving time.',
        );
        $this->assertGreaterThanOrEqual(5, $activity->total_ascent_meters);
        $this->assertLessThanOrEqual(180, $activity->total_ascent_meters);
        $this->assertGreaterThanOrEqual(135, $activity->average_heart_rate);
        $this->assertLessThanOrEqual(172, $activity->average_heart_rate);
    }

    public function test_the_without_metrics_state_leaves_all_three_empty_and_never_zero(): void
    {
        $activity = Activity::factory()->withoutMetrics()->create()->refresh();

        $this->assertNull($activity->average_pace_seconds_per_km);
        $this->assertNull($activity->total_ascent_meters);
        $this->assertNull($activity->average_heart_rate);
    }

    public function test_the_without_heart_rate_state_keeps_pace_and_ascent(): void
    {
        $activity = Activity::factory()->withoutHeartRate()->create()->refresh();

        $this->assertNotNull($activity->average_pace_seconds_per_km);
        $this->assertNotNull($activity->total_ascent_meters);
        $this->assertNull($activity->average_heart_rate);
    }

    public function test_the_run_metrics_are_mass_assignable_integers(): void
    {
        $activity = Activity::factory()->create();

        $activity->update([
            'average_pace_seconds_per_km' => '323',
            'total_ascent_meters' => '45',
            'average_heart_rate' => '152',
        ]);

        $this->assertSame(323, $activity->refresh()->average_pace_seconds_per_km);
        $this->assertSame(45, $activity->total_ascent_meters);
        $this->assertSame(152, $activity->average_heart_rate);
    }

    public function test_the_default_definition_carries_no_extended_metric(): void
    {
        $activity = Activity::factory()->create()->refresh();

        foreach (ExtendedRunMetricsMigrationTest::EXTENDED_COLUMNS as $column) {
            $this->assertNull($activity->{$column}, "{$column} must stay empty by default.");
        }
    }

    public function test_the_extended_metrics_state_is_coherent_with_the_run(): void
    {
        Activity::factory()->count(25)->withExtendedMetrics()->create();

        Activity::all()->each(function (Activity $activity): void {
            $averagePace = $activity->moving_time_seconds / ($activity->distance_meters / 1_000);

            $this->assertGreaterThanOrEqual($activity->moving_time_seconds, $activity->elapsed_time_seconds);
            $this->assertLessThan($averagePace, $activity->best_pace_seconds_per_km);
            $this->assertLessThanOrEqual($activity->max_elevation_meters, $activity->min_elevation_meters);
            $this->assertGreaterThan($activity->average_running_cadence, $activity->max_running_cadence);
            $this->assertGreaterThan($activity->average_heart_rate, $activity->max_heart_rate);
            $this->assertEqualsWithDelta(
                $activity->average_running_cadence * $activity->moving_time_seconds / 60,
                $activity->steps,
                1.0,
            );
            $this->assertIsFloat($activity->aerobic_training_effect);
            $this->assertNull($activity->training_stress_score);
            $this->assertNull($activity->hr_zone_1_seconds);
        });
    }

    public function test_the_api_only_state_keeps_zones_within_the_elapsed_time_and_splits_within_the_distance(): void
    {
        Activity::factory()->state(['distance_meters' => 800, 'moving_time_seconds' => 260])->withExtendedMetrics()->withApiOnlyMetrics()->create();
        Activity::factory()->state(['distance_meters' => 3_000, 'moving_time_seconds' => 900])->withExtendedMetrics()->withApiOnlyMetrics()->create();
        Activity::factory()->count(20)->withExtendedMetrics()->withApiOnlyMetrics()->create();

        Activity::all()->each(function (Activity $activity): void {
            $zones = $activity->hr_zone_1_seconds + $activity->hr_zone_2_seconds + $activity->hr_zone_3_seconds
                + $activity->hr_zone_4_seconds + $activity->hr_zone_5_seconds;

            $this->assertLessThanOrEqual($activity->elapsed_time_seconds, $zones);
            $this->assertContains($activity->location_name, ['Lazise', 'Verona', 'Bolzano']);
            $this->assertSame($activity->distance_meters >= 1_000, $activity->fastest_1k_seconds !== null);
            $this->assertSame($activity->distance_meters >= 1_609, $activity->fastest_mile_seconds !== null);
            $this->assertSame($activity->distance_meters >= 5_000, $activity->fastest_5k_seconds !== null);
        });
    }

    public function test_the_training_stress_score_state_sets_only_the_score(): void
    {
        $activity = Activity::factory()->withTrainingStressScore()->create()->refresh();

        $this->assertGreaterThan(0, $activity->training_stress_score);
        $this->assertNull($activity->calories);
    }

    public function test_it_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->create();

        $this->assertTrue($activity->user->is($user));
        $this->assertTrue($user->activities->contains($activity));
    }

    public function test_deleting_the_user_cascades_the_activities_away(): void
    {
        $user = User::factory()->create();
        Activity::factory()->count(3)->for($user)->create();

        $user->delete();

        $this->assertSame(0, Activity::where('user_id', $user->id)->count());
    }

    public function test_the_trail_run_state_produces_a_trail_run(): void
    {
        $this->assertSame(SportType::TrailRun, Activity::factory()->trailRun()->create()->sport_type);
    }

    public function test_the_virtual_run_state_produces_a_virtual_run(): void
    {
        $this->assertSame(SportType::VirtualRun, Activity::factory()->virtualRun()->create()->sport_type);
    }

    public function test_the_on_date_state_pins_the_activity_to_that_day(): void
    {
        $date = Carbon::parse('2026-03-14');

        $activity = Activity::factory()->onDate($date)->create();

        $this->assertSame('2026-03-14', $activity->activity_date->toDateString());
        $this->assertSame('2026-03-14', $activity->start_date_local->toDateString());
    }

    public function test_an_activity_has_no_strava_attribute(): void
    {
        $activity = Activity::factory()->create()->fresh();

        $this->assertArrayNotHasKey('strava_activity_id', $activity->getAttributes());
    }

    public function test_a_run_is_a_csv_import_with_no_garmin_id_unless_it_was_synced(): void
    {
        $activity = Activity::factory()->create()->fresh();

        $this->assertSame(ActivitySource::GarminCsv, $activity->source);
        $this->assertNull($activity->garmin_activity_id);
        $this->assertSame(ActivitySource::GarminCsv, Activity::factory()->garminImported()->create()->fresh()->source);
    }

    public function test_the_garmin_synced_state_carries_a_unique_eleven_digit_garmin_id(): void
    {
        $runs = Activity::factory()->garminSynced()->count(3)->create();

        foreach ($runs as $run) {
            $this->assertSame(ActivitySource::GarminApi, $run->fresh()->source);
            $this->assertIsInt($run->fresh()->garmin_activity_id);
            $this->assertSame(11, strlen((string) $run->garmin_activity_id));
        }

        $this->assertCount(3, $runs->pluck('garmin_activity_id')->unique());
    }

    public function test_the_source_and_the_garmin_id_are_not_mass_assignable(): void
    {
        $activity = Activity::factory()->create();

        $activity->fill([
            'source' => ActivitySource::GarminApi->value,
            'garmin_activity_id' => 12_345_678_901,
            'name' => 'Renamed',
        ]);

        $this->assertSame('Renamed', $activity->name);
        $this->assertSame(ActivitySource::GarminCsv, $activity->source);
        $this->assertNull($activity->garmin_activity_id);
    }

    public function test_the_sport_type_enum_exposes_exactly_the_three_running_types(): void
    {
        $this->assertSame(['Run', 'TrailRun', 'VirtualRun'], SportType::values());
    }

    public function test_on_days_between_includes_both_ends_and_nothing_beyond_them(): void
    {
        $athlete = User::factory()->create();

        foreach (['2026-10-04', '2026-10-05', '2026-10-08', '2026-10-11', '2026-10-12'] as $date) {
            Activity::factory()->for($athlete)->onDate(Carbon::parse($date))->create();
        }

        $dates = Activity::query()
            ->onDaysBetween(Carbon::parse('2026-10-05'), Carbon::parse('2026-10-11'))
            ->orderBy('activity_date')
            ->get()
            ->map(fn (Activity $activity): string => $activity->activity_date->toDateString())
            ->all();

        $this->assertSame(['2026-10-05', '2026-10-08', '2026-10-11'], $dates);
    }

    public function test_on_days_between_does_not_depend_on_the_format_the_date_was_stored_in(): void
    {
        $athlete = User::factory()->create();

        Activity::factory()->for($athlete)->onDate(Carbon::parse('2026-10-11'))->create(); // "Y-m-d 00:00:00" through the cast
        $storedAsPlainDate = Activity::factory()->for($athlete)->onDate(Carbon::parse('2026-10-11'))->create();
        DB::table('activities')->where('id', $storedAsPlainDate->id)->update(['activity_date' => '2026-10-11']);
        $dayAfter = Activity::factory()->for($athlete)->onDate(Carbon::parse('2026-10-12'))->create();
        DB::table('activities')->where('id', $dayAfter->id)->update(['activity_date' => '2026-10-12']);

        $found = Activity::query()->onDaysBetween(Carbon::parse('2026-10-05'), Carbon::parse('2026-10-11'))->count();

        $this->assertSame(2, $found);
    }
}
