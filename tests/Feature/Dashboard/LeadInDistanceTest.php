<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\User;
use App\Support\Heatmap\DailyRunningDistance;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Heatmap\LeadInDistance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadInDistanceTest extends TestCase
{
    use RefreshDatabase;

    private function runOn(User $athlete, string $date, int $meters): void
    {
        Activity::factory()->for($athlete)->create([
            'distance_meters' => $meters,
            'start_date_local' => $date.' 07:00:00',
            'activity_date' => $date,
        ]);
    }

    public function test_it_returns_the_three_weeks_before_the_window_in_one_query(): void
    {
        $athlete = User::factory()->create();
        $window = HeatmapWindow::current(CarbonImmutable::parse('2026-09-05')); // starts Monday 18 May

        $this->runOn($athlete, '2026-04-26', 9_000); // the Sunday before the lead-in: out
        $this->runOn($athlete, '2026-04-27', 12_000); // first day of the lead-in: in, week 1
        $this->runOn($athlete, '2026-05-03', 10_000); // week 1
        $this->runOn($athlete, '2026-05-11', 10_000); // week 3, two runs on one day are summed
        $this->runOn($athlete, '2026-05-11', 8_600);
        $this->runOn($athlete, '2026-05-17', 1_000); // last day of the lead-in: week 3
        $this->runOn($athlete, '2026-05-18', 30_000); // the window's own first day: out

        DB::enableQueryLog();
        $weeklyMeters = app(LeadInDistance::class)($athlete, $window);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([22_000, 0, 19_600], $weeklyMeters);
        $this->assertCount(1, $queries);
    }

    public function test_another_athletes_runs_are_not_counted(): void
    {
        $athlete = User::factory()->create();
        $window = HeatmapWindow::current(CarbonImmutable::parse('2026-09-05'));

        $this->runOn(User::factory()->create(), '2026-05-12', 15_000);

        $this->assertSame([0, 0, 0], app(LeadInDistance::class)($athlete, $window));
    }

    public function test_a_run_on_the_boundary_is_counted_on_one_side_of_it_only(): void
    {
        $athlete = User::factory()->create();
        $window = HeatmapWindow::current(CarbonImmutable::parse('2026-09-05')); // starts Monday 18 May

        $this->runOn($athlete, '2026-05-17', 11_000); // Sunday before the window: the lead-in's
        $this->runOn($athlete, '2026-05-18', 13_000); // first Monday: the window's

        $leadIn = app(LeadInDistance::class)($athlete, $window);
        $daily = app(DailyRunningDistance::class)($athlete, $window);

        $this->assertSame([0, 0, 11_000], $leadIn);
        $this->assertSame(['2026-05-18' => 13_000], $daily);
    }

    public function test_the_window_counts_its_last_day_as_stored_through_eloquent(): void
    {
        $athlete = User::factory()->create();
        $window = HeatmapWindow::current(CarbonImmutable::parse('2026-10-11')); // ends Sunday 11 Oct

        $this->runOn($athlete, '2026-10-11', 17_507);
        $this->runOn($athlete, '2026-10-12', 5_000); // the Monday after: another window's

        $daily = app(DailyRunningDistance::class)($athlete, $window);

        $this->assertSame(['2026-10-11' => 17_507], $daily);
    }
}
