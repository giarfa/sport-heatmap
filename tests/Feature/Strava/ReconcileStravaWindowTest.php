<?php

namespace Tests\Feature\Strava;

use App\Actions\Strava\ReconcileStravaWindow;
use App\Data\StravaActivity;
use App\Data\SyncOutcome;
use App\Enums\ActivitySource;
use App\Models\Activity;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_a_strava_run_on_the_windows_last_day_that_strava_no_longer_returns_is_deleted_and_a_garmin_run_survives(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $stravaRun = Activity::factory()->for($user)->onDate($window->endsOn)->create();
        $garminRun = Activity::factory()->for($user)->garminImported()->onDate($window->endsOn)->create();

        $outcome = $this->reconcile($user, $window, []);

        $this->assertSame(1, $outcome->deleted);
        $this->assertFalse(Activity::whereKey($stravaRun->id)->exists());
        $this->assertTrue(Activity::whereKey($garminRun->id)->exists());
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

    public function test_a_garmin_won_twin_that_strava_still_returns_keeps_its_garmin_values(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $start = Carbon::today()->setTime(9, 33, 27);

        $twin = Activity::factory()->for($user)->garminOverwritten()->create([
            'strava_activity_id' => 42,
            'name' => 'Paese Corsa',
            'distance_meters' => 8_290,
            'moving_time_seconds' => 3_263,
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
        ]);

        $outcome = $this->reconcile($user, $window, [
            $this->stravaActivity([
                'id' => 42,
                'name' => 'Morning Run',
                'distance' => 8_150.0,
                'moving_time' => 3_300,
                'start_date_local' => $start->copy()->subSeconds(40)->format('Y-m-d\TH:i:s\Z'),
            ]),
        ]);

        $this->assertSame(0, $outcome->upserted);
        $this->assertSame(0, $outcome->deleted);
        $this->assertSame(1, Activity::count());

        $stored = $twin->fresh();
        $this->assertSame(ActivitySource::GarminCsv, $stored->source);
        $this->assertSame('Paese Corsa', $stored->name);
        $this->assertSame(8_290, $stored->distance_meters);
        $this->assertSame(3_263, $stored->moving_time_seconds);
        $this->assertSame($start->format('Y-m-d H:i:s'), $stored->start_date_local->format('Y-m-d H:i:s'));
    }

    public function test_a_new_activity_stores_its_pace_ascent_and_heart_rate(): void
    {
        $user = $this->allowlistedAthlete();

        $this->reconcile($user, HeatmapWindow::current(), [
            $this->stravaActivity([
                'id' => 1,
                'average_speed' => 3.095,
                'total_elevation_gain' => 44.6,
                'average_heartrate' => 151.7,
            ]),
        ]);

        $activity = Activity::sole();
        $this->assertSame(323, $activity->average_pace_seconds_per_km);
        $this->assertSame(45, $activity->total_ascent_meters);
        $this->assertSame(152, $activity->average_heart_rate);
    }

    public function test_a_run_without_a_heart_rate_stores_the_other_metrics_and_an_empty_heart_rate(): void
    {
        $user = $this->allowlistedAthlete();

        $this->reconcile($user, HeatmapWindow::current(), [
            $this->stravaActivity(['id' => 1, 'average_speed' => 2.5, 'total_elevation_gain' => 30.0]),
        ]);

        $activity = Activity::sole();
        $this->assertSame(400, $activity->average_pace_seconds_per_km);
        $this->assertSame(30, $activity->total_ascent_meters);
        $this->assertNull($activity->average_heart_rate);
    }

    public function test_a_zero_average_speed_stores_no_pace_rather_than_zero(): void
    {
        $user = $this->allowlistedAthlete();

        $this->reconcile($user, HeatmapWindow::current(), [
            $this->stravaActivity(['id' => 1, 'average_speed' => 0, 'total_elevation_gain' => 12.0, 'average_heartrate' => 140.0]),
        ]);

        $activity = Activity::sole();
        $this->assertNull($activity->average_pace_seconds_per_km);
        $this->assertSame(12, $activity->total_ascent_meters);
        $this->assertSame(140, $activity->average_heart_rate);
    }

    public function test_a_strava_run_stored_before_the_metrics_existed_gains_them_on_the_next_sync(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $start = Carbon::today()->setTime(7, 15);

        Activity::factory()->for($user)->withoutMetrics()->create([
            'strava_activity_id' => 9,
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
        ]);

        $payload = [
            'id' => 9,
            'start_date_local' => $start->format('Y-m-d\TH:i:s\Z'),
            'average_speed' => 2.86,
            'total_elevation_gain' => 45.6,
            'average_heartrate' => 151.7,
        ];

        $first = $this->reconcile($user, $window, [$this->stravaActivity($payload)]);
        $second = $this->reconcile($user, $window, [$this->stravaActivity($payload)]);

        $activity = Activity::sole();
        $this->assertSame(350, $activity->average_pace_seconds_per_km);
        $this->assertSame(46, $activity->total_ascent_meters);
        $this->assertSame(152, $activity->average_heart_rate);
        $this->assertSame(1, $first->upserted, 'The backfill is a change, so it counts as upserted.');
        $this->assertSame(0, $second->upserted, 'Once backfilled, the same fetch changes nothing.');
    }

    public function test_a_metric_strava_stops_reporting_is_cleared_on_a_strava_run(): void
    {
        $user = $this->allowlistedAthlete();
        $start = Carbon::today()->setTime(7, 15);

        Activity::factory()->for($user)->create([
            'strava_activity_id' => 9,
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
            'average_heart_rate' => 150,
        ]);

        $this->reconcile($user, HeatmapWindow::current(), [
            $this->stravaActivity(['id' => 9, 'start_date_local' => $start->format('Y-m-d\TH:i:s\Z'), 'average_speed' => 3.0]),
        ]);

        $this->assertNull(Activity::sole()->average_heart_rate);
    }

    public function test_a_garmin_won_twin_keeps_its_metrics_when_strava_returns_different_ones(): void
    {
        $user = $this->allowlistedAthlete();
        $start = Carbon::today()->setTime(9, 33, 27);

        $twin = Activity::factory()->for($user)->garminOverwritten()->create([
            'strava_activity_id' => 42,
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
            'average_pace_seconds_per_km' => 327,
            'total_ascent_meters' => 64,
            'average_heart_rate' => 151,
        ]);

        $outcome = $this->reconcile($user, HeatmapWindow::current(), [
            $this->stravaActivity([
                'id' => 42,
                'start_date_local' => $start->format('Y-m-d\TH:i:s\Z'),
                'average_speed' => 2.0,
                'total_elevation_gain' => 10.0,
                'average_heartrate' => 120.0,
            ]),
        ]);

        $stored = $twin->fresh();
        $this->assertSame(0, $outcome->upserted);
        $this->assertSame(327, $stored->average_pace_seconds_per_km);
        $this->assertSame(64, $stored->total_ascent_meters);
        $this->assertSame(151, $stored->average_heart_rate);
    }

    public function test_a_garmin_won_twin_without_metrics_stays_without_them(): void
    {
        $user = $this->allowlistedAthlete();
        $start = Carbon::today()->setTime(9, 33, 27);

        $twin = Activity::factory()->for($user)->garminOverwritten()->withoutMetrics()->create([
            'strava_activity_id' => 42,
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
        ]);

        $this->reconcile($user, HeatmapWindow::current(), [
            $this->stravaActivity([
                'id' => 42,
                'start_date_local' => $start->format('Y-m-d\TH:i:s\Z'),
                'average_speed' => 3.0,
                'total_elevation_gain' => 20.0,
                'average_heartrate' => 140.0,
            ]),
        ]);

        $stored = $twin->fresh();
        $this->assertNull($stored->average_pace_seconds_per_km);
        $this->assertNull($stored->total_ascent_meters);
        $this->assertNull($stored->average_heart_rate);
    }

    public function test_a_late_linked_garmin_run_keeps_its_metrics_and_its_empty_ones(): void
    {
        $user = $this->allowlistedAthlete();
        $start = Carbon::today()->setTime(9, 33, 27);

        $imported = Activity::factory()->for($user)->garminImported()->create([
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
            'average_pace_seconds_per_km' => 330,
            'total_ascent_meters' => null,
            'average_heart_rate' => null,
        ]);

        $this->reconcile($user, HeatmapWindow::current(), [
            $this->stravaActivity([
                'id' => 77,
                'start_date_local' => $start->copy()->addSeconds(90)->format('Y-m-d\TH:i:s\Z'),
                'average_speed' => 2.0,
                'total_elevation_gain' => 80.0,
                'average_heartrate' => 160.0,
            ]),
        ]);

        $linked = $imported->fresh();
        $this->assertSame(77, $linked->strava_activity_id);
        $this->assertSame(330, $linked->average_pace_seconds_per_km);
        $this->assertNull($linked->total_ascent_meters, 'A metric the CSV left empty is not filled from Strava.');
        $this->assertNull($linked->average_heart_rate);
    }

    public function test_a_garmin_won_twin_that_strava_no_longer_returns_is_not_deleted(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $twin = Activity::factory()->for($user)->garminOverwritten()->onDate(Carbon::today())->create();

        $outcome = $this->reconcile($user, $window, []);

        $this->assertSame(0, $outcome->deleted);
        $this->assertTrue(Activity::whereKey($twin->id)->exists());
    }

    public function test_a_garmin_imported_run_absent_from_the_fetch_is_not_deleted(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $imported = Activity::factory()->for($user)->garminImported()->onDate(Carbon::today())->create();

        $outcome = $this->reconcile($user, $window, []);

        $this->assertSame(0, $outcome->deleted);
        $this->assertTrue(Activity::whereKey($imported->id)->exists());
    }

    public function test_a_strava_sourced_run_absent_from_the_fetch_is_still_deleted_beside_garmin_rows(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        $strava = Activity::factory()->for($user)->onDate(Carbon::today())->create();
        Activity::factory()->for($user)->garminImported()->onDate(Carbon::today())->create();
        Activity::factory()->for($user)->garminOverwritten()->onDate(Carbon::today())->create();

        $outcome = $this->reconcile($user, $window, []);

        $this->assertSame(1, $outcome->deleted);
        $this->assertFalse(Activity::whereKey($strava->id)->exists());
        $this->assertSame(2, Activity::count());
    }

    public function test_a_late_strava_twin_links_to_the_garmin_imported_run_instead_of_duplicating_it(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $start = Carbon::today()->setTime(9, 33, 27);

        $imported = Activity::factory()->for($user)->garminImported()->create([
            'name' => 'Paese Corsa',
            'distance_meters' => 8_290,
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
        ]);

        $outcome = $this->reconcile($user, $window, [
            $this->stravaActivity([
                'id' => 77,
                'name' => 'Morning Run',
                'distance' => 8_150.0,
                'start_date_local' => $start->copy()->addSeconds(90)->format('Y-m-d\TH:i:s\Z'),
            ]),
        ]);

        $this->assertSame(0, $outcome->upserted);
        $this->assertSame(1, Activity::count());

        $linked = $imported->fresh();
        $this->assertSame(77, $linked->strava_activity_id);
        $this->assertSame(ActivitySource::GarminCsv, $linked->source);
        $this->assertSame('Paese Corsa', $linked->name);
        $this->assertSame(8_290, $linked->distance_meters);
    }

    public function test_a_strava_activity_121_seconds_from_a_garmin_imported_run_is_a_separate_strava_run(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $start = Carbon::today()->setTime(9, 33, 27);

        $imported = Activity::factory()->for($user)->garminImported()->create([
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
        ]);

        $outcome = $this->reconcile($user, $window, [
            $this->stravaActivity([
                'id' => 77,
                'start_date_local' => $start->copy()->addSeconds(121)->format('Y-m-d\TH:i:s\Z'),
            ]),
        ]);

        $this->assertSame(1, $outcome->upserted);
        $this->assertSame(2, Activity::count());
        $this->assertNull($imported->fresh()->strava_activity_id);
        $this->assertSame(ActivitySource::Strava, Activity::where('strava_activity_id', 77)->sole()->source);
    }

    public function test_one_garmin_imported_run_links_to_only_one_strava_activity(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $start = Carbon::today()->setTime(9, 33, 27);

        Activity::factory()->for($user)->garminImported()->create([
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
        ]);

        $this->reconcile($user, $window, [
            $this->stravaActivity(['id' => 77, 'start_date_local' => $start->copy()->addSeconds(30)->format('Y-m-d\TH:i:s\Z')]),
            $this->stravaActivity(['id' => 78, 'start_date_local' => $start->copy()->addSeconds(60)->format('Y-m-d\TH:i:s\Z')]),
        ]);

        $this->assertSame(2, Activity::count());
        $this->assertSame(1, Activity::where('source', ActivitySource::GarminCsv)->where('strava_activity_id', 77)->count());
        $this->assertSame(ActivitySource::Strava, Activity::where('strava_activity_id', 78)->sole()->source);
    }

    public function test_reconciling_twice_around_garmin_rows_is_idempotent(): void
    {
        $user = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $start = Carbon::today()->setTime(9, 33, 27);

        Activity::factory()->for($user)->garminImported()->create([
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
        ]);
        Activity::factory()->for($user)->garminOverwritten()->onDate(Carbon::yesterday())->create(['strava_activity_id' => 42]);

        $fetch = [
            $this->stravaActivity(['id' => 77, 'start_date_local' => $start->copy()->addSeconds(30)->format('Y-m-d\TH:i:s\Z')]),
            $this->stravaActivity(['id' => 42]),
            $this->stravaActivity(['id' => 5]),
        ];

        $this->reconcile($user, $window, $fetch);

        $snapshot = Activity::orderBy('id')->get()->map->only(['id', 'strava_activity_id', 'source', 'name', 'updated_at'])->all();

        $outcome = $this->reconcile($user, $window, $fetch);

        $this->assertSame(0, $outcome->upserted);
        $this->assertSame(0, $outcome->deleted);
        $this->assertEquals($snapshot, Activity::orderBy('id')->get()->map->only(['id', 'strava_activity_id', 'source', 'name', 'updated_at'])->all());
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
