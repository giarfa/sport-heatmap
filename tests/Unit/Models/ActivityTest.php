<?php

namespace Tests\Unit\Models;

use App\Enums\ActivitySource;
use App\Enums\SportType;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_a_default_activity_is_strava_sourced_and_links_to_strava(): void
    {
        $activity = Activity::factory()->create(['strava_activity_id' => 1234567890]);

        $this->assertSame(ActivitySource::Strava, $activity->fresh()->source);
        $this->assertFalse($activity->isGarminSourced());
        $this->assertSame('https://www.strava.com/activities/1234567890', $activity->stravaUrl());
    }

    public function test_a_garmin_imported_activity_has_no_strava_id_and_no_strava_link(): void
    {
        $activity = Activity::factory()->garminImported()->create()->fresh();

        $this->assertSame(ActivitySource::GarminCsv, $activity->source);
        $this->assertNull($activity->strava_activity_id);
        $this->assertTrue($activity->isGarminSourced());
        $this->assertNull($activity->stravaUrl());
    }

    /**
     * The twin keeps its Strava id only so the sync can recognise it; the Strava page
     * shows values that are no longer the stored ones, so it is not linked (FR-008).
     */
    public function test_a_garmin_overwritten_twin_keeps_its_strava_id_but_has_no_strava_link(): void
    {
        $activity = Activity::factory()->garminOverwritten()->create()->fresh();

        $this->assertSame(ActivitySource::GarminCsv, $activity->source);
        $this->assertNotNull($activity->strava_activity_id);
        $this->assertNull($activity->stravaUrl());
    }

    public function test_many_garmin_imported_activities_coexist_under_the_unique_strava_id(): void
    {
        Activity::factory()->count(3)->garminImported()->create();

        $this->assertSame(3, Activity::whereNull('strava_activity_id')->count());
    }

    public function test_the_sport_type_enum_exposes_exactly_the_three_running_types(): void
    {
        $this->assertSame(['Run', 'TrailRun', 'VirtualRun'], SportType::values());
    }
}
