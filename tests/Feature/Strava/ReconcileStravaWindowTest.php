<?php

namespace Tests\Feature\Strava;

use App\Actions\Strava\ReconcileStravaWindow;
use App\Data\StravaActivity;
use App\Data\SyncOutcome;
use App\Models\Activity;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReconcileStravaWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_activity_is_created_with_the_right_attributes(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $outcome = $this->reconcile($user, $window, [
            $this->stravaActivity(['id' => 1, 'distance' => 8_042.6, 'name' => 'Morning Run']),
        ]);

        $this->assertSame(1, $outcome->upserted);
        $this->assertSame(0, $outcome->deleted);

        $activity = Activity::sole();
        $this->assertSame($user->id, $activity->user_id);
        $this->assertSame(1, $activity->strava_activity_id);
        $this->assertSame(8_043, $activity->distance_meters);
        $this->assertSame('Morning Run', $activity->name);
    }

    public function test_a_changed_distance_a_rename_and_a_sport_type_change_propagate(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $this->reconcile($user, $window, [
            $this->stravaActivity(['id' => 1, 'sport_type' => 'Run', 'name' => 'Easy shakeout', 'distance' => 5_000]),
        ]);

        $outcome = $this->reconcile($user, $window, [
            $this->stravaActivity(['id' => 1, 'sport_type' => 'TrailRun', 'name' => 'Forest loop', 'distance' => 12_000]),
        ]);

        $this->assertSame(1, $outcome->upserted);

        $activity = Activity::sole();
        $this->assertSame('TrailRun', $activity->sport_type->value);
        $this->assertSame('Forest loop', $activity->name);
        $this->assertSame(12_000, $activity->distance_meters);
    }

    public function test_an_activity_absent_from_the_fetch_inside_the_window_is_deleted(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $this->reconcile($user, $window, [$this->stravaActivity(['id' => 1])]);

        $outcome = $this->reconcile($user, $window, []);

        $this->assertSame(0, $outcome->upserted);
        $this->assertSame(1, $outcome->deleted);
        $this->assertSame(0, Activity::count());
    }

    public function test_an_activity_outside_the_window_is_left_untouched(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $outsideActivity = Activity::factory()
            ->for($user)
            ->onDate($window->startsOn->subDays(5))
            ->create();

        $outcome = $this->reconcile($user, $window, []);

        $this->assertSame(0, $outcome->deleted);
        $this->assertTrue(Activity::whereKey($outsideActivity->id)->exists());
    }

    public function test_a_sport_type_edited_to_a_non_running_type_upstream_is_deleted(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $this->reconcile($user, $window, [$this->stravaActivity(['id' => 1, 'sport_type' => 'Run'])]);

        // The activity is now a Ride on Strava, so it never becomes a StravaActivity
        // DTO and the fetch's running set no longer contains it.
        $outcome = $this->reconcile($user, $window, []);

        $this->assertSame(1, $outcome->deleted);
        $this->assertSame(0, Activity::count());
    }

    public function test_an_empty_fetch_clears_the_whole_window(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        Activity::factory()->count(3)->for($user)->onDate($window->startsOn)->create();

        $outcome = $this->reconcile($user, $window, []);

        $this->assertSame(3, $outcome->deleted);
        $this->assertSame(0, Activity::count());
    }

    public function test_reconciling_the_same_fetch_twice_is_idempotent(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $fetch = [$this->stravaActivity(['id' => 1]), $this->stravaActivity(['id' => 2])];

        $this->reconcile($user, $window, $fetch);

        $rowCountBefore = Activity::count();
        $updatedAtSnapshot = Activity::orderBy('id')->pluck('updated_at')->all();

        $outcome = $this->reconcile($user, $window, $fetch);

        $this->assertSame(0, $outcome->upserted);
        $this->assertSame(0, $outcome->deleted);
        $this->assertSame($rowCountBefore, Activity::count());
        $this->assertEquals($updatedAtSnapshot, Activity::orderBy('id')->pluck('updated_at')->all());
    }

    public function test_a_second_athletes_activities_are_never_touched(): void
    {
        $athleteOne = $this->allowlistedAthlete();
        $athleteTwo = User::factory()->stravaConnected()->create();
        $window = HeatmapWindow::current();

        $athleteTwoActivity = Activity::factory()->for($athleteTwo)->onDate($window->startsOn)->create();

        $this->reconcile($athleteOne, $window, [$this->stravaActivity(['id' => 999])]);

        $this->assertTrue(Activity::whereKey($athleteTwoActivity->id)->exists());
        $this->assertSame(1, $athleteTwo->activities()->count());
    }

    public function test_a_failure_mid_apply_rolls_back_the_whole_transaction(): void
    {
        $athleteOne = $this->allowlistedAthlete();
        $athleteTwo = User::factory()->stravaConnected()->create();
        $window = HeatmapWindow::current();

        // Belongs to a different athlete, so athleteOne's updateOrCreate() will
        // not find it and will attempt an insert that collides on the unique index.
        Activity::factory()->for($athleteTwo)->create(['strava_activity_id' => 555]);

        try {
            $this->reconcile($athleteOne, $window, [
                $this->stravaActivity(['id' => 1]),
                $this->stravaActivity(['id' => 555]),
            ]);
            $this->fail('A unique-index collision mid-apply must raise a QueryException.');
        } catch (QueryException) {
            $this->assertSame(0, $athleteOne->activities()->count());
        }
    }

    /**
     * @param  array<int, StravaActivity>  $activities
     */
    private function reconcile(User $user, HeatmapWindow $window, array $activities): SyncOutcome
    {
        return app(ReconcileStravaWindow::class)($user, $window, $activities);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function stravaActivity(array $overrides = []): StravaActivity
    {
        $payload = array_merge([
            'id' => 1,
            'sport_type' => 'Run',
            'name' => 'Morning Run',
            'distance' => 8_042.3,
            'moving_time' => 2_400,
            'start_date_local' => now()->format('Y-m-d\TH:i:s\Z'),
        ], $overrides);

        $activity = StravaActivity::fromApiPayload($payload);

        if ($activity === null) {
            throw new RuntimeException('Test payload failed to build a StravaActivity.');
        }

        return $activity;
    }
}
