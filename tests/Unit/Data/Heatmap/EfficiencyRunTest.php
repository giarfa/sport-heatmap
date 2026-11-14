<?php

namespace Tests\Unit\Data\Heatmap;

use App\Data\Heatmap\EfficiencyRun;
use App\Models\Activity;
use App\Models\User;
use App\Support\Heatmap\EfficiencyRuns;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EfficiencyRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
    }

    public function test_a_metric_that_was_not_recorded_stays_null_not_zero(): void
    {
        $run = EfficiencyRun::fromActivity(Activity::factory()->withoutMetrics()->make(['user_id' => 1]));

        $this->assertNull($run->paceSecondsPerKm);
        $this->assertNull($run->heartRate);
        $this->assertNull($run->ascentMeters);
    }

    public function test_the_weekday_counts_from_monday(): void
    {
        $this->assertSame(0, $this->efficiencyRun(date: '2026-10-12')->weekday());
        $this->assertSame(6, $this->efficiencyRun(date: '2026-10-18')->weekday());
    }

    public function test_it_reads_the_window_with_its_lead_in_and_the_same_weeks_a_year_earlier_in_two_queries(): void
    {
        $athlete = $this->signInOwner();
        $window = HeatmapWindow::current();
        $leadInStart = $window->startsOn->subWeeks(3);

        $this->runOn($athlete, $window->endsOn);
        $this->runOn($athlete, $leadInStart);
        $this->runOn($athlete, $leadInStart->subDay());
        $this->runOn($athlete, $window->endsOn->addDay());
        $this->runOn($athlete, $window->endsOn->subWeeks(52));
        $this->runOn($athlete, $leadInStart->subWeeks(52));
        $this->runOn($athlete, $leadInStart->subWeeks(52)->subDay());
        $this->runOn($athlete, $window->endsOn->subWeeks(52)->addDay());
        $this->runOn(User::factory()->create(), $window->endsOn);

        DB::enableQueryLog();
        $sample = (new EfficiencyRuns)($athlete, $window);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $queries);
        $this->assertSame(
            [$leadInStart->toDateString(), $window->endsOn->toDateString()],
            array_map(fn (EfficiencyRun $run): string => $run->date->toDateString(), $sample->runs),
        );
        $this->assertSame(
            [$leadInStart->subWeeks(52)->toDateString(), $window->endsOn->subWeeks(52)->toDateString()],
            array_map(fn (EfficiencyRun $run): string => $run->date->toDateString(), $sample->lastYearRuns),
        );
    }

    private function efficiencyRun(string $date = '2026-10-12', int $distanceMeters = 8_000, ?int $ascentMeters = 20): EfficiencyRun
    {
        return new EfficiencyRun(CarbonImmutable::parse($date), $distanceMeters, 330, 148, $ascentMeters);
    }

    private function runOn(User $athlete, CarbonImmutable $day): void
    {
        Activity::factory()->for($athlete)->onDate($day)->create();
    }
}
