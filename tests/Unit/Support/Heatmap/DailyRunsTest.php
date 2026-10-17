<?php

namespace Tests\Unit\Support\Heatmap;

use App\Data\Heatmap\DayRun;
use App\Models\Activity;
use App\Support\Heatmap\DailyRuns;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
    }

    public function test_runs_are_grouped_by_day_in_start_order(): void
    {
        $athlete = $this->allowlistedAthlete();

        Activity::factory()->for($athlete)->create([
            'name' => 'Evening shakeout',
            'start_date_local' => '2026-10-10 18:30:00',
            'activity_date' => '2026-10-10',
        ]);
        Activity::factory()->for($athlete)->create([
            'name' => 'Morning run',
            'start_date_local' => '2026-10-10 07:15:00',
            'activity_date' => '2026-10-10',
        ]);
        Activity::factory()->for($athlete)->create([
            'name' => 'Other day',
            'start_date_local' => '2026-10-08 07:15:00',
            'activity_date' => '2026-10-08',
        ]);

        $runs = (new DailyRuns)($athlete, HeatmapWindow::current());

        $this->assertSame(['2026-10-08', '2026-10-10'], array_keys($runs));
        $this->assertSame(['Morning run', 'Evening shakeout'], array_map(fn (DayRun $run): string => $run->name, $runs['2026-10-10']));
    }

    public function test_it_ignores_other_athletes_and_days_outside_the_window(): void
    {
        $athlete = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();

        Activity::factory()->for($this->allowlistedAthlete(['strava_athlete_id' => '10000002']))->onDate(Carbon::parse('2026-10-10'))->create();
        Activity::factory()->for($athlete)->onDate($window->startsOn->subDay())->create();

        $this->assertSame([], (new DailyRuns)($athlete, $window));
    }

    public function test_it_reads_the_whole_window_in_one_query_whatever_the_number_of_days(): void
    {
        $athlete = $this->allowlistedAthlete();

        foreach (range(0, 20) as $daysAgo) {
            Activity::factory()->for($athlete)->onDate(Carbon::parse('2026-10-12')->subDays($daysAgo))->create();
        }

        DB::enableQueryLog();
        $runs = (new DailyRuns)($athlete, HeatmapWindow::current());

        $this->assertCount(1, DB::getQueryLog());
        $this->assertCount(21, $runs);
    }

    public function test_a_run_carries_its_metrics_and_only_strava_runs_link_out(): void
    {
        $athlete = $this->allowlistedAthlete();

        Activity::factory()->for($athlete)->onDate(Carbon::parse('2026-10-10'))->create([
            'strava_activity_id' => 4_242,
            'average_pace_seconds_per_km' => 323,
            'total_ascent_meters' => 45,
            'average_heart_rate' => 152,
        ]);
        Activity::factory()->for($athlete)->garminImported()->withoutMetrics()->onDate(Carbon::parse('2026-10-09'))->create();

        $runs = (new DailyRuns)($athlete, HeatmapWindow::current());

        $strava = $runs['2026-10-10'][0];
        $this->assertSame('https://www.strava.com/activities/4242', $strava->stravaUrl);
        $this->assertSame('5:23 /km', $strava->pace());

        $garmin = $runs['2026-10-09'][0];
        $this->assertNull($garmin->stravaUrl);
        $this->assertNull($garmin->pace());
        $this->assertNull($garmin->heartRate());
    }

    public function test_the_windows_first_and_last_day_are_read_and_the_days_around_them_are_not(): void
    {
        $athlete = $this->allowlistedAthlete();
        $window = HeatmapWindow::current(Carbon::parse('2026-10-11')); // Monday 22 Jun to Sunday 11 Oct

        Activity::factory()->for($athlete)->onDate($window->startsOn->subDay())->create(['name' => 'Sunday before']);
        Activity::factory()->for($athlete)->onDate($window->startsOn)->create(['name' => 'First Monday']);
        Activity::factory()->for($athlete)->onDate($window->endsOn)->create(['name' => 'Last Sunday']);
        Activity::factory()->for($athlete)->onDate($window->endsOn->addDay())->create(['name' => 'Monday after']);

        $runs = (new DailyRuns)($athlete, $window);

        $this->assertSame(['2026-06-22', '2026-10-11'], array_keys($runs));
        $this->assertSame('First Monday', $runs['2026-06-22'][0]->name);
        $this->assertSame('Last Sunday', $runs['2026-10-11'][0]->name);
    }
}
