<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Database\ExtendedRunMetricsMigrationTest;
use Tests\TestCase;

/**
 * FR-022 stores the extended metrics for later views and shows none of them: the grid,
 * the day cell detail, the legend, the scale and the trend render exactly as before.
 */
class ExtendedMetricsNotDisplayedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-11 12:00:00');
        $this->signInOwner();
    }

    public function test_the_dashboard_shows_none_of_a_runs_extended_values(): void
    {
        $run = Activity::factory()->for(auth()->user())->garminSynced()->onDate(Carbon::parse('2026-10-09'))
            ->state(['distance_meters' => 8_290, 'moving_time_seconds' => 2_678, 'average_pace_seconds_per_km' => 323, 'total_ascent_meters' => 45, 'average_heart_rate' => 152])
            ->withExtendedMetrics()->withApiOnlyMetrics()->withTrainingStressScore()
            ->create(['calories' => 1_234, 'steps' => 8_765, 'location_name' => 'Zzyzx Springs', 'training_stress_score' => 77.7]);

        $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<template id="day-2026-10-09">', $html);
        foreach (['Zzyzx Springs', '1,234', '1234', '8,765', '8765', '77.7', 'Calories', 'Cadence', 'Training Stress'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "The dashboard must not display \"{$needle}\".");
        }

        $this->assertNotNull($run->fresh()->location_name);
    }

    public function test_the_page_is_identical_with_and_without_extended_metrics(): void
    {
        $run = Activity::factory()->for(auth()->user())->garminSynced()->onDate(Carbon::parse('2026-10-09'))
            ->state(['distance_meters' => 8_290, 'moving_time_seconds' => 2_678, 'average_pace_seconds_per_km' => 323, 'total_ascent_meters' => 45, 'average_heart_rate' => 152])
            ->withExtendedMetrics()->withApiOnlyMetrics()->withTrainingStressScore()
            ->create();

        $withMetrics = $this->normalised((string) $this->get(route('dashboard'))->assertOk()->getContent());

        DB::table('activities')->update(array_fill_keys(ExtendedRunMetricsMigrationTest::EXTENDED_COLUMNS, null));
        Cache::flush();

        $withoutMetrics = $this->normalised((string) $this->get(route('dashboard'))->assertOk()->getContent());

        $this->assertSame($withoutMetrics, $withMetrics);
        $this->assertStringContainsString('5:23 /km', $withMetrics);
        $this->assertStringContainsString('45 m', $withMetrics);
        $this->assertStringContainsString('152 bpm', $withMetrics);
        $this->assertNull($run->fresh()->calories);
    }

    public function test_a_previous_window_shows_none_of_them_either(): void
    {
        Activity::factory()->for(auth()->user())->onDate(Carbon::parse('2026-07-01'))
            ->withExtendedMetrics()->withApiOnlyMetrics()
            ->create(['location_name' => 'Zzyzx Springs']);

        $html = (string) $this->get(route('dashboard', ['to' => HeatmapWindow::current()->startsOn->subDay()->toDateString()]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Zzyzx Springs', $html);
    }

    /**
     * The CSRF token and nonces differ between renders; nothing else may.
     */
    private function normalised(string $html): string
    {
        return (string) preg_replace(['/name="_token" value="[^"]+"/', '/nonce="[^"]+"/'], ['name="_token"', 'nonce=""'], $html);
    }
}
