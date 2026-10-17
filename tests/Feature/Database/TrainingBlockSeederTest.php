<?php

namespace Tests\Feature\Database;

use App\Enums\ActivitySource;
use App\Enums\SportType;
use App\Enums\SyncRunStatus;
use App\Models\Activity;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Database\Seeders\TrainingBlockSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrainingBlockSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Zero-based positions of the shaped weeks inside the 16-week block, mirroring the
     * seeder's own constants.
     */
    private const ILLNESS_WEEK_POSITION = 9;

    private const PEAK_WEEK_POSITION = 12;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TrainingBlockSeeder::class);
    }

    public function test_it_creates_exactly_one_demo_athlete_with_a_strava_identity(): void
    {
        $this->assertSame(1, User::count());
        $this->assertNotNull(User::sole()->strava_athlete_id);
    }

    /**
     * A volume sanity check, not a spec. The seeder can construct 52-94 activities
     * (13 normal weeks of 3-6 runs, 3 double-run days, 1 illness run, 8 peak runs and
     * 1-4 runs in the partial current week), so the bounds have to cover that whole
     * range or the suite goes red at random.
     */
    public function test_it_seeds_enough_activities_to_exercise_the_dashboard(): void
    {
        $this->assertGreaterThanOrEqual(50, $this->activities()->count());
        $this->assertLessThanOrEqual(95, $this->activities()->count());
    }

    public function test_the_block_spans_sixteen_consecutive_iso_weeks_ending_with_the_current_one(): void
    {
        $weeks = $this->weeklyDistances();

        $this->assertCount(16, $weeks);
        $this->assertSame(Carbon::today()->isoFormat('GGGG-WW'), $weeks->keys()->last());
    }

    /**
     * On a Monday the current week has a single elapsed day, which is the tightest
     * case for "16 distinct weeks, none of them empty".
     */
    public function test_the_block_still_spans_sixteen_weeks_when_today_is_a_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 08:00:00'));

        User::query()->delete();
        SyncRun::query()->delete();

        $this->seed(TrainingBlockSeeder::class);

        $weeks = $this->weeklyDistances();

        $this->assertCount(16, $weeks);
        $this->assertSame(Carbon::today()->isoFormat('GGGG-WW'), $weeks->keys()->last());
        $this->assertSame(
            0,
            Activity::whereDate('activity_date', '>', Carbon::today()->toDateString())->count(),
        );
    }

    /**
     * Position 9 of the 16-week block is the illness week. Asserting the position — not
     * merely that "some week is low" — is what catches the shaping regressing onto the
     * wrong week.
     */
    public function test_the_illness_week_sits_well_below_the_block_median(): void
    {
        $totals = $this->weeklyDistances()->values();

        $this->assertSame($totals->min(), $totals[self::ILLNESS_WEEK_POSITION]);
        $this->assertLessThan($totals->median() / 2, $totals[self::ILLNESS_WEEK_POSITION]);
    }

    /**
     * Position 12 of the 16-week block is the peak week, and it must be a strict
     * maximum so the intensity scale has an unambiguous top.
     */
    public function test_the_peak_week_is_the_highest_volume_week_of_the_block(): void
    {
        $totals = $this->weeklyDistances()->values();

        $this->assertSame($totals->max(), $totals[self::PEAK_WEEK_POSITION]);

        $runnersUp = $totals->sortDesc()->values();

        $this->assertGreaterThan($runnersUp[1], $runnersUp[0]);
    }

    public function test_no_activity_is_dated_in_the_future(): void
    {
        $this->assertSame(
            0,
            Activity::whereDate('activity_date', '>', Carbon::today()->toDateString())->count(),
        );
    }

    public function test_every_activity_date_matches_its_local_start_date(): void
    {
        $this->activities()->each(function (Activity $activity): void {
            $this->assertSame(
                $activity->start_date_local->toDateString(),
                $activity->activity_date->toDateString(),
            );
        });
    }

    public function test_every_activity_is_one_of_the_three_running_sport_types(): void
    {
        $this->activities()->each(function (Activity $activity): void {
            $this->assertContains($activity->sport_type, SportType::cases());
        });
    }

    public function test_the_block_seeds_two_garmin_imported_runs_and_one_garmin_overwritten_twin(): void
    {
        $this->assertSame(2, $this->activities()->where('source', ActivitySource::GarminCsv)->whereNull('strava_activity_id')->count());
        $this->assertSame(1, $this->activities()->where('source', ActivitySource::GarminCsv)->whereNotNull('strava_activity_id')->count());
    }

    public function test_no_garmin_sourced_run_sits_in_the_current_week(): void
    {
        $this->assertSame(
            0,
            Activity::where('source', ActivitySource::GarminCsv)
                ->whereDate('activity_date', '>=', Carbon::today()->startOfWeek()->toDateString())
                ->count(),
        );
    }

    public function test_the_block_includes_days_with_two_runs(): void
    {
        $daysWithTwoRuns = $this->activities()
            ->groupBy(fn (Activity $activity): string => $activity->activity_date->toDateString())
            ->filter(fn (Collection $activities): bool => $activities->count() > 1);

        $this->assertGreaterThanOrEqual(1, $daysWithTwoRuns->count());
    }

    public function test_the_demo_covers_every_run_metric_state(): void
    {
        $activities = $this->activities();

        $this->assertTrue(
            $activities->contains(fn (Activity $activity): bool => $activity->average_pace_seconds_per_km !== null
                && $activity->total_ascent_meters !== null
                && $activity->average_heart_rate !== null),
            'No run carries all three metrics.',
        );
        $this->assertTrue(
            $activities->contains(fn (Activity $activity): bool => $activity->average_pace_seconds_per_km !== null
                && $activity->average_heart_rate === null),
            'No run lacks only a heart rate.',
        );
        $this->assertTrue(
            $activities->contains(fn (Activity $activity): bool => $activity->average_pace_seconds_per_km === null
                && $activity->total_ascent_meters === null
                && $activity->average_heart_rate === null),
            'No run lacks every metric.',
        );
    }

    public function test_the_garmin_imported_runs_have_no_metrics(): void
    {
        $garminImported = $this->activities()->where('source', ActivitySource::GarminCsv)->whereNull('strava_activity_id');

        $this->assertCount(2, $garminImported);
        $garminImported->each(function (Activity $activity): void {
            $this->assertNull($activity->average_pace_seconds_per_km);
            $this->assertNull($activity->total_ascent_meters);
            $this->assertNull($activity->average_heart_rate);
        });
    }

    public function test_one_day_lists_a_strava_run_and_a_garmin_run(): void
    {
        $mixedDays = $this->activities()
            ->groupBy(fn (Activity $activity): string => $activity->activity_date->toDateString())
            ->filter(fn (Collection $activities): bool => $activities->contains('source', ActivitySource::Strava)
                && $activities->contains('source', ActivitySource::GarminCsv));

        $this->assertGreaterThanOrEqual(1, $mixedDays->count());
    }

    public function test_the_stored_pace_is_the_pace_of_the_moving_time(): void
    {
        $this->activities()
            ->filter(fn (Activity $activity): bool => $activity->average_pace_seconds_per_km !== null)
            ->each(function (Activity $activity): void {
                $this->assertEqualsWithDelta(
                    $activity->moving_time_seconds / ($activity->distance_meters / 1000),
                    $activity->average_pace_seconds_per_km,
                    1.0,
                );
            });
    }

    public function test_the_most_recent_sync_run_succeeded(): void
    {
        $this->assertGreaterThanOrEqual(1, SyncRun::count());
        $this->assertSame(
            SyncRunStatus::Succeeded,
            SyncRun::orderByDesc('started_at')->firstOrFail()->status,
        );
    }

    public function test_every_activity_belongs_to_the_seeded_athlete(): void
    {
        $this->assertSame(0, Activity::whereNot('user_id', User::sole()->id)->count());
    }

    /**
     * Seven weeks of Garmin history sit before the block, Monday to Sunday, the 0 km week
     * included, and every run in it is a Garmin import with no metrics.
     */
    public function test_seven_weeks_of_garmin_history_sit_before_the_block(): void
    {
        $history = $this->historyActivities();

        $this->assertTrue($history->every(fn (Activity $activity): bool => $activity->source === ActivitySource::GarminCsv
            && $activity->strava_activity_id === null
            && $activity->average_pace_seconds_per_km === null
            && $activity->total_ascent_meters === null
            && $activity->average_heart_rate === null));

        $firstWeek = $this->blockStart()->subWeeks(7);
        $this->assertSame($firstWeek->toDateString(), $history->min('activity_date')->startOfWeek()->toDateString());

        $totals = [];
        foreach (range(0, 6) as $position) {
            $weekStart = $firstWeek->copy()->addWeeks($position);
            $totals[] = $history
                ->filter(fn (Activity $activity): bool => $activity->activity_date->between($weekStart, $weekStart->copy()->endOfWeek()))
                ->sum('distance_meters');
        }

        $this->assertSame([28_400, 31_000, 33_500, 36_200, 22_000, 0, 18_600], $totals);
    }

    public function test_previous_has_runs_to_show_from_the_current_window(): void
    {
        $oldest = Carbon::parse((string) Activity::min('activity_date'))->startOfDay();

        $this->assertTrue($oldest->lessThan($this->blockStart()));
        $this->assertTrue($oldest->lessThan(HeatmapWindow::current()->startsOn));
    }

    private function blockStart(): Carbon
    {
        return Carbon::today()->startOfWeek()->subWeeks(15);
    }

    /**
     * The 16-week block: everything from its first week on.
     *
     * @return Collection<int, Activity>
     */
    private function activities(): Collection
    {
        return Activity::where('activity_date', '>=', $this->blockStart()->toDateString())->get();
    }

    /**
     * The Garmin history before the block.
     *
     * @return Collection<int, Activity>
     */
    private function historyActivities(): Collection
    {
        return Activity::where('activity_date', '<', $this->blockStart()->toDateString())->get();
    }

    /**
     * Total distance per ISO year-week, oldest first.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    private function weeklyDistances(): \Illuminate\Support\Collection
    {
        return $this->activities()
            ->groupBy(fn (Activity $activity): string => $activity->activity_date->isoFormat('GGGG-WW'))
            ->map(fn (Collection $activities): int => (int) $activities->sum('distance_meters'))
            ->sortKeys();
    }
}
