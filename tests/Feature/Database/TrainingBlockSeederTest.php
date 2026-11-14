<?php

namespace Tests\Feature\Database;

use App\Data\Heatmap\MeasuredRun;
use App\Enums\ActivitySource;
use App\Enums\AerobicEfficiencyState;
use App\Enums\EfficiencyRunKind;
use App\Enums\GarminSyncOutcome;
use App\Enums\GarminSyncTrigger;
use App\Enums\SportType;
use App\Models\Activity;
use App\Models\GarminSync;
use App\Models\User;
use App\Support\Heatmap\BuildAerobicEfficiency;
use App\Support\Heatmap\EfficiencyRuns;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Heatmap\MeasureAerobicEfficiency;
use Database\Seeders\TrainingBlockSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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

    public function test_it_creates_exactly_one_demo_athlete(): void
    {
        $this->assertSame(1, User::count());
        $this->assertSame('Demo Athlete', User::sole()->name);
    }

    public function test_the_demo_athlete_is_the_owner_with_no_password_yet(): void
    {
        $athlete = User::sole();

        $this->assertSame(User::OWNER_USERNAME, $athlete->username);
        $this->assertNull($athlete->password);
    }

    /**
     * A volume sanity check, not a spec. The seeder can construct 52-94 activities
     * (13 normal weeks of 3-6 runs, 3 double-run days, 1 illness run, 8 peak runs,
     * 1-4 runs in the partial current week, and the one run under 3 km), so the bounds
     * have to cover that whole range or the suite goes red at random.
     */
    public function test_it_seeds_enough_activities_to_exercise_the_dashboard(): void
    {
        $this->assertGreaterThanOrEqual(50, $this->activities()->count());
        $this->assertLessThanOrEqual(96, $this->activities()->count());
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

    public function test_the_first_block_weeks_come_from_an_older_export_with_no_metrics(): void
    {
        $oldExport = $this->activities()->filter(
            fn (Activity $activity): bool => $activity->activity_date->lessThan($this->blockStart()->addWeeks(2)),
        );

        $this->assertNotEmpty($oldExport);
        $oldExport->each(function (Activity $activity): void {
            $this->assertNull($activity->average_pace_seconds_per_km);
            $this->assertNull($activity->total_ascent_meters);
            $this->assertNull($activity->average_heart_rate);
        });
    }

    public function test_recent_synced_runs_carry_the_api_only_metrics(): void
    {
        $synced = $this->activities()->where('source', ActivitySource::GarminApi);

        $this->assertNotEmpty($synced);
        $synced->each(function (Activity $activity): void {
            $this->assertNotNull($activity->location_name);
            $this->assertNotNull($activity->hr_zone_1_seconds);
            $this->assertNotNull($activity->hr_zone_5_seconds);
            $this->assertNotNull($activity->calories);
            $this->assertNull($activity->training_stress_score);
        });
        $this->assertTrue($synced->contains(fn (Activity $activity): bool => $activity->fastest_5k_seconds !== null));
    }

    public function test_some_csv_runs_carry_a_training_stress_score_and_none_carry_api_only_metrics(): void
    {
        $csvRuns = $this->activities()->where('source', ActivitySource::GarminCsv)
            ->filter(fn (Activity $activity): bool => $activity->calories !== null);

        $this->assertNotEmpty($csvRuns);
        $this->assertTrue($csvRuns->contains(fn (Activity $activity): bool => $activity->training_stress_score !== null));
        $csvRuns->each(function (Activity $activity): void {
            $this->assertNull($activity->hr_zone_1_seconds);
            $this->assertNull($activity->fastest_1k_seconds);
            $this->assertNull($activity->location_name);
        });
    }

    public function test_the_oldest_runs_carry_no_extended_metric(): void
    {
        $oldest = $this->historyActivities()->merge($this->activities()->filter(
            fn (Activity $activity): bool => $activity->activity_date->lessThan($this->blockStart()->addWeeks(2)),
        ));

        $this->assertNotEmpty($oldest);
        $oldest->each(function (Activity $activity): void {
            foreach (ExtendedRunMetricsMigrationTest::EXTENDED_COLUMNS as $column) {
                $this->assertNull($activity->{$column}, "{$column} must be empty on a run of the older export.");
            }
        });
    }

    public function test_every_seeded_run_is_coherent(): void
    {
        Activity::all()->each(function (Activity $activity): void {
            if ($activity->elapsed_time_seconds !== null) {
                $this->assertGreaterThanOrEqual($activity->moving_time_seconds, $activity->elapsed_time_seconds);
            }

            if ($activity->min_elevation_meters !== null) {
                $this->assertLessThanOrEqual($activity->max_elevation_meters, $activity->min_elevation_meters);
            }

            if ($activity->best_pace_seconds_per_km !== null) {
                $this->assertLessThan(
                    $activity->moving_time_seconds / ($activity->distance_meters / 1_000),
                    $activity->best_pace_seconds_per_km,
                );
            }

            if ($activity->hr_zone_1_seconds !== null) {
                $zones = $activity->hr_zone_1_seconds + $activity->hr_zone_2_seconds + $activity->hr_zone_3_seconds
                    + $activity->hr_zone_4_seconds + $activity->hr_zone_5_seconds;

                $this->assertLessThanOrEqual($activity->elapsed_time_seconds, $zones);
            }
        });
    }

    public function test_the_demo_has_no_strava_columns_and_one_succeeded_garmin_sync(): void
    {
        $this->assertFalse(Schema::hasTable('sync_runs'));
        $this->assertFalse(Schema::hasColumn('users', 'strava_athlete_id'));
        $this->assertFalse(Schema::hasColumn('activities', 'strava_activity_id'));

        $sync = GarminSync::sole();

        $this->assertSame(GarminSyncOutcome::Succeeded, $sync->outcome);
        $this->assertSame(GarminSyncTrigger::Scheduled, $sync->trigger);
        $this->assertTrue($sync->full_history);
        $this->assertNull($sync->failure_cause);
        $this->assertTrue($sync->finished_at->isToday());
        $this->assertTrue($sync->finished_at->lessThanOrEqualTo(Carbon::now()));
        $this->assertTrue($sync->started_at->lessThan($sync->finished_at));
    }

    /**
     * The last four weeks are what the sync wrote and carry Garmin ids; everything older
     * is a CSV import with none. The sync row counts exactly the runs it wrote.
     */
    public function test_the_last_four_weeks_are_api_synced_and_older_runs_are_csv_imports(): void
    {
        $syncedFrom = Carbon::today()->startOfWeek()->subWeeks(3);

        $synced = Activity::where('activity_date', '>=', $syncedFrom->toDateString())->get();
        $imported = Activity::where('activity_date', '<', $syncedFrom->toDateString())->get();

        $this->assertNotEmpty($synced);
        $this->assertNotEmpty($imported);
        $this->assertTrue($synced->every(fn (Activity $activity): bool => $activity->source === ActivitySource::GarminApi
            && $activity->garmin_activity_id !== null));
        $this->assertTrue($imported->every(fn (Activity $activity): bool => $activity->source === ActivitySource::GarminCsv
            && $activity->garmin_activity_id === null));
        $this->assertCount($synced->count(), $synced->pluck('garmin_activity_id')->unique());
        $this->assertSame($synced->count(), GarminSync::sole()->new_count);
    }

    public function test_every_activity_belongs_to_the_seeded_athlete(): void
    {
        $this->assertSame(0, Activity::whereNot('user_id', User::sole()->id)->count());
    }

    /**
     * Seven weeks of history sit before the block, Monday to Sunday, the 0 km week
     * included, and every run in it comes from an export with no metrics.
     */
    public function test_seven_weeks_of_garmin_history_sit_before_the_block(): void
    {
        $history = $this->historyActivities();

        $this->assertTrue($history->every(fn (Activity $activity): bool => $activity->average_pace_seconds_per_km === null
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

    public function test_the_current_window_measures_aerobic_efficiency_with_every_kind_of_run(): void
    {
        $window = HeatmapWindow::current();
        $sample = (new EfficiencyRuns)(User::sole(), $window);
        $measurement = (new MeasureAerobicEfficiency)($window, $sample);

        $this->assertGreaterThanOrEqual(MeasureAerobicEfficiency::MIN_QUALIFYING_RUNS, $measurement->qualifyingCount);
        $this->assertGreaterThanOrEqual(146, $measurement->referenceHeartRate);
        $this->assertLessThanOrEqual(150, $measurement->referenceHeartRate);
        $this->assertNotEmpty(array_filter($measurement->linePoints, fn (?float $point): bool => $point !== null));

        $kinds = array_map(fn (MeasuredRun $run): EfficiencyRunKind => $run->kind, $measurement->runs);

        $this->assertContains(EfficiencyRunKind::Flat, $kinds);
        $this->assertContains(EfficiencyRunKind::Hilly, $kinds);
        $this->assertContains(EfficiencyRunKind::Steep, $kinds);
    }

    public function test_the_block_has_a_run_well_above_the_median_heart_rate_and_a_run_under_three_kilometres(): void
    {
        $measurement = (new MeasureAerobicEfficiency)(
            HeatmapWindow::current(),
            (new EfficiencyRuns)(User::sole(), HeatmapWindow::current()),
        );

        $this->assertTrue($this->activities()->contains(
            fn (Activity $activity): bool => $activity->average_heart_rate > $measurement->referenceHeartRate + 10,
        ));
        $this->assertTrue($this->activities()->contains(
            fn (Activity $activity): bool => $activity->distance_meters < 3_000 && $activity->average_pace_seconds_per_km !== null,
        ));
    }

    public function test_the_same_weeks_one_year_earlier_draw_last_years_line_with_a_break(): void
    {
        $window = HeatmapWindow::current();
        $measurement = (new MeasureAerobicEfficiency)($window, (new EfficiencyRuns)(User::sole(), $window));

        $this->assertTrue($measurement->hasLastYear);
        $this->assertNotEmpty(array_filter($measurement->lastYearPoints, fn (?float $point): bool => $point !== null));
        $this->assertContains(null, $measurement->lastYearPoints, 'The year-earlier gap leaves a week with no point.');
    }

    public function test_the_card_is_measured_and_shows_every_key_entry(): void
    {
        $window = HeatmapWindow::current();
        $measurement = (new MeasureAerobicEfficiency)($window, (new EfficiencyRuns)(User::sole(), $window));
        $grid = app(HeatmapBuilder::class)($window, [], Carbon::now());
        $card = (new BuildAerobicEfficiency)($measurement, $grid->weeks, $grid->label());

        $this->assertSame(AerobicEfficiencyState::Measured, $card->state);
        $this->assertTrue($card->hasHilly);
        $this->assertTrue($card->hasSteep);
        $this->assertTrue($card->hasLastYear);
        $this->assertTrue($card->hasMedianLine());
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
     * The seven weeks of history right before the block. The runs one year earlier sit
     * further back and are not part of it.
     *
     * @return Collection<int, Activity>
     */
    private function historyActivities(): Collection
    {
        return Activity::where('activity_date', '>=', $this->blockStart()->subWeeks(7)->toDateString())
            ->where('activity_date', '<', $this->blockStart()->toDateString())
            ->get();
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
