<?php

namespace Tests\Feature\Database;

use App\Enums\SportType;
use App\Enums\SyncRunStatus;
use App\Models\Activity;
use App\Models\SyncRun;
use App\Models\User;
use Database\Seeders\TrainingBlockSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrainingBlockSeederTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_seeds_enough_activities_to_exercise_the_dashboard(): void
    {
        $this->assertGreaterThanOrEqual(60, Activity::count());
        $this->assertLessThanOrEqual(90, Activity::count());
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

    public function test_the_illness_week_sits_well_below_the_block_median(): void
    {
        $weeks = $this->weeklyDistances();

        $this->assertLessThan($weeks->median() / 2, $weeks->min());
    }

    public function test_the_peak_week_is_the_highest_volume_week_of_the_block(): void
    {
        $weeks = $this->weeklyDistances();

        $sorted = $weeks->sortDesc()->values();

        $this->assertGreaterThan($sorted[1], $sorted[0]);
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
     * @return Collection<int, Activity>
     */
    private function activities(): Collection
    {
        return Activity::all();
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
