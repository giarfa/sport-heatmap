<?php

namespace Tests\Unit\Models;

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

    public function test_the_sport_type_enum_exposes_exactly_the_three_running_types(): void
    {
        $this->assertSame(['Run', 'TrailRun', 'VirtualRun'], SportType::values());
    }
}
