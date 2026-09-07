<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The grid cache: a hit skips the aggregate query, a successful sync forgets
 * it, and the calendar day is part of the key so it cannot survive midnight.
 */
class HeatmapGridCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Config::set('strava.retry_backoff_ms', [0, 0, 0]);
        $this->travelTo('2026-09-05 09:00:00'); // Saturday
    }

    public function test_a_second_request_the_same_day_serves_the_cached_grid(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);
        $this->getDashboard()->assertOk();

        Activity::factory()->for($athlete)->onDate(HeatmapWindow::current()->endsOn->subDays(2))->create([
            'distance_meters' => 9_000,
        ]);

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringNotContainsString('9.0 km', $html);
    }

    public function test_a_successful_sync_forgets_the_cached_grid(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);
        $this->getDashboard()->assertOk();

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([$this->syncedActivityPayload()]),
        ]);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('8.0 km', $html);
    }

    public function test_a_different_calendar_day_produces_a_different_cache_key(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);
        $this->getDashboard()->assertOk();

        Activity::factory()->for($athlete)->onDate(HeatmapWindow::current()->endsOn->subDays(2))->create([
            'distance_meters' => 9_000,
        ]);

        $this->travelTo('2026-09-06 09:00:00'); // Sunday — same ISO week, different calendar day

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('9.0 km', $html);
    }

    private function getDashboard(): TestResponse
    {
        return $this->get('/');
    }

    /**
     * @return array<string, mixed>
     */
    private function syncedActivityPayload(): array
    {
        return [
            'id' => 12_345_678,
            'sport_type' => 'Run',
            'name' => 'Morning Run',
            'distance' => 8_000.0,
            'moving_time' => 2_400,
            'start_date_local' => HeatmapWindow::current()->endsOn->subDays(2)->format('Y-m-d\T12:00:00\Z'),
        ];
    }
}
