<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\SyncRun;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The rendered dashboard for every sync state FR-007 names: healthy, failed,
 * stale, never-synced, an empty window, and a first sync in flight. Stale
 * data must never be presented as current, so the failed/stale cases assert
 * the warning and the still-rendered grid together, not either in isolation.
 */
class SyncStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00'); // Saturday
    }

    public function test_a_recent_success_renders_the_relative_time_with_no_alert(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        SyncRun::factory()->create(['finished_at' => now()->subHours(6)]);

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('Synced 6 hours ago', $html);
        $this->assertStringNotContainsString('role="alert"', $html);
    }

    public function test_a_failed_last_run_renders_the_alert_with_its_reason_and_a_reconnect_action_and_the_grid_still_renders(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        SyncRun::factory()->create([
            'started_at' => now()->subHours(10)->subMinute(),
            'finished_at' => now()->subHours(10),
        ]);
        $failedRun = SyncRun::factory()->failed()->create([
            'started_at' => now()->subHours(2)->subMinutes(1),
            'finished_at' => now()->subHours(2),
        ]);

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString($failedRun->error_message, $html);
        $this->assertStringContainsString(route('strava.redirect'), $html);
        $this->assertStringContainsString('Reconnect Strava', $html);
        $this->assertStringContainsString('<table class="hm"', $html);

        // The chip is aria-hidden but not display:none once a banner is on the
        // page, so its dot colour must still agree with the warning state.
        $this->assertStringContainsString('sync-dot--warn', $html);
        $this->assertStringNotContainsString('sync-dot--ok', $html);
    }

    public function test_a_success_older_than_48_hours_renders_the_stale_warning_naming_the_data_date_and_the_grid_still_renders(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        SyncRun::factory()->create([
            'started_at' => now()->subHours(50)->subSeconds(20),
            'finished_at' => now()->subHours(50),
        ]);

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Last successful sync was 2 days ago', $html);
        $this->assertStringContainsString('The nightly sync has not completed since 3 Sep.', $html);
        $this->assertStringContainsString('<table class="hm"', $html);
    }

    public function test_a_never_synced_account_renders_the_first_run_banner_not_an_error(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        $response = $this->getDashboard();
        $html = $response->getContent() ?: '';

        $response->assertOk();
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Waiting for the first sync', $html);
        $this->assertStringContainsString('>Sync</h3>', $html);
    }

    public function test_a_connected_account_with_zero_runs_in_the_window_renders_the_empty_line_above_a_full_grid(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        SyncRun::factory()->create(['finished_at' => now()->subHours(6)]);

        $response = $this->getDashboard();
        $html = $response->getContent() ?: '';

        $response->assertOk();
        $this->assertStringContainsString(
            'No runs found in the last 16 weeks. The map fills in after your next activity syncs.',
            $html,
        );
        $this->assertStringContainsString('<table class="hm"', $html);
    }

    public function test_the_sync_detail_card_shows_the_last_run_outcome_and_a_window_scoped_count(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        SyncRun::factory()->create([
            'started_at' => now()->subHours(6)->subSeconds(20),
            'finished_at' => now()->subHours(6),
        ]);

        $window = HeatmapWindow::current();
        Activity::factory()->for($athlete)->onDate($window->endsOn->subDays(2))->create();
        Activity::factory()->for($athlete)->onDate($window->endsOn->subDays(4))->create();
        Activity::factory()->for($athlete)->onDate($window->startsOn->subDays(1))->create(); // outside the window

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('>Sync</h3>', $html);
        $this->assertStringContainsString('6 hours ago', $html);
        $this->assertStringContainsString('Succeeded', $html);
        $this->assertStringContainsString('<dt>Runs in window</dt>', $html);
        $this->assertStringContainsString('<dd class="sync-detail-value">2</dd>', $html);
    }

    public function test_a_first_sync_in_flight_renders_the_skeleton_and_not_the_empty_window_line(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        SyncRun::factory()->running()->create();

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('aria-busy="true"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringNotContainsString('No runs found in the last 16 weeks.', $html);
    }

    public function test_the_signed_out_connect_screen_renders_with_no_chip_and_no_banner(): void
    {
        $response = $this->getDashboard();
        $html = $response->getContent() ?: '';

        $response->assertViewIs('strava.connect');
        $this->assertStringNotContainsString('sync-chip', $html);
        $this->assertStringNotContainsString('role="alert"', $html);
    }

    private function getDashboard(): TestResponse
    {
        return $this->get('/');
    }
}
