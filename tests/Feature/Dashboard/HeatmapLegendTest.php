<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The rendered legend: it agrees with the cells it was bucketed with, flips to
 * fixed bands on sparse and degenerate windows, and recomputes after a sync
 * rather than serving a cached scale.
 */
class HeatmapLegendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Config::set('strava.retry_backoff_ms', [0, 0, 0]);
        $this->travelTo('2026-09-05 09:00:00'); // Saturday
    }

    public function test_the_legend_is_present_without_interaction_and_agrees_with_the_rendered_cells(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        $endsOn = HeatmapWindow::current()->endsOn;

        Activity::factory()->for($athlete)->onDate($endsOn->subDays(10))->create(['distance_meters' => 4_000]);
        Activity::factory()->for($athlete)->onDate($endsOn->subDays(8))->create(['distance_meters' => 8_000]);
        Activity::factory()->for($athlete)->onDate($endsOn->subDays(6))->create(['distance_meters' => 12_000]);
        Activity::factory()->for($athlete)->onDate($endsOn->subDays(4))->create(['distance_meters' => 20_000]);

        $html = $this->getDashboard()->getContent() ?: '';

        // Always visible: present without interaction on the connected dashboard.
        $this->assertStringContainsString('hm-legend-title', $html);
        $this->assertStringContainsString('Calibrated to your 4 running days in this window — recomputed after every sync.', $html);

        // The thresholds computed by R-7 over [4000, 8000, 12000, 20000] are 7000 / 10000 / 14000.
        $this->assertStringContainsString('0.1 – 7.0 km', $html);
        $this->assertStringContainsString('7.0 – 10.0 km', $html);
        $this->assertStringContainsString('10.0 – 14.0 km', $html);
        $this->assertStringContainsString('14.0+ km', $html);

        // The same thresholds bucket the cells actually rendered — a regression that
        // recalibrates the legend without the cells (or vice versa) fails here.
        $this->assertStringContainsString('hm-cell hm-cell--l1', $html); // 4.0 km <= p25 (7.0)
        $this->assertStringContainsString('hm-cell hm-cell--l2', $html); // 8.0 km <= p50 (10.0)
        $this->assertStringContainsString('hm-cell hm-cell--l3', $html); // 12.0 km <= p75 (14.0)
        $this->assertStringContainsString('hm-cell hm-cell--l4', $html); // 20.0 km > p75
    }

    public function test_a_window_with_three_runs_flips_the_legend_to_fixed_bands(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        $endsOn = HeatmapWindow::current()->endsOn;

        Activity::factory()->for($athlete)->onDate($endsOn->subDays(10))->create(['distance_meters' => 6_000]);
        Activity::factory()->for($athlete)->onDate($endsOn->subDays(8))->create(['distance_meters' => 9_000]);
        Activity::factory()->for($athlete)->onDate($endsOn->subDays(6))->create(['distance_meters' => 15_000]);

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('Only 3 running days in this window — too few to calibrate, so fixed bands are shown instead.', $html);
        $this->assertStringContainsString('0.1 – 5.0 km', $html);
        $this->assertStringContainsString('5.0 – 10.0 km', $html);
        $this->assertStringContainsString('10.0 – 20.0 km', $html);
        $this->assertStringContainsString('20.0+ km', $html);
    }

    public function test_a_window_where_the_percentiles_collapse_flips_to_fixed_bands_and_does_not_render_one_level(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        $endsOn = HeatmapWindow::current()->endsOn;
        $distancesMetres = [1_000, 5_000, 5_000, 5_000, 5_000, 5_000, 5_000, 9_000];

        foreach ($distancesMetres as $offset => $metres) {
            Activity::factory()->for($athlete)->onDate($endsOn->subDays($offset * 2 + 3))->create([
                'distance_meters' => $metres,
            ]);
        }

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('The runs in this window are too alike to calibrate a scale — fixed bands are shown instead.', $html);

        // Fixed bands (5000 / 10000 / 20000) still tell the 1 km, 5 km and 9 km
        // runs apart, so the running days are not all rendered at one level.
        $this->assertStringContainsString('hm-cell hm-cell--l1', $html);
        $this->assertStringContainsString('hm-cell hm-cell--l2', $html);
    }

    public function test_a_sync_that_changes_the_distribution_recomputes_the_legend_on_the_next_request(): void
    {
        /*
         * Reconciliation treats each sync's fetched window as authoritative
         * (it deletes activities the fetch no longer reports), so the
         * distribution has to be built through two real syncs rather than by
         * seeding factory rows the second sync would otherwise delete.
         */
        $athlete = $this->allowlistedAthlete();
        $this->actingAs($athlete);

        $endsOn = HeatmapWindow::current()->endsOn;

        /*
         * Http::fake() merges stub callbacks rather than replacing them, so
         * faking the same URL twice would leave the first response winning
         * for both syncs. A sequence is what actually serves a different
         * body to the second request.
         */
        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::sequence()
                ->push([
                    $this->syncedActivityPayload(1_001, 4_000, $endsOn->subDays(10)),
                    $this->syncedActivityPayload(1_002, 8_000, $endsOn->subDays(8)),
                    $this->syncedActivityPayload(1_003, 12_000, $endsOn->subDays(6)),
                ])
                ->push([
                    $this->syncedActivityPayload(1_001, 4_000, $endsOn->subDays(10)),
                    $this->syncedActivityPayload(1_002, 8_000, $endsOn->subDays(8)),
                    $this->syncedActivityPayload(1_003, 12_000, $endsOn->subDays(6)),
                    $this->syncedActivityPayload(1_004, 20_000, $endsOn->subDays(4)),
                ]),
        ]);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $before = $this->getDashboard()->getContent() ?: '';
        $this->assertStringContainsString('Only 3 running days in this window — too few to calibrate, so fixed bands are shown instead.', $before);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $after = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('Calibrated to your 4 running days in this window — recomputed after every sync.', $after);
        $this->assertStringContainsString('0.1 – 7.0 km', $after);
        $this->assertStringContainsString('7.0 – 10.0 km', $after);
        $this->assertStringContainsString('10.0 – 14.0 km', $after);
        $this->assertStringContainsString('14.0+ km', $after);
    }

    private function getDashboard(): TestResponse
    {
        return $this->get('/');
    }

    /**
     * @return array<string, mixed>
     */
    private function syncedActivityPayload(int $id, int $distanceMeters, CarbonInterface $date): array
    {
        return [
            'id' => $id,
            'sport_type' => 'Run',
            'name' => 'Synced Run',
            'distance' => (float) $distanceMeters,
            'moving_time' => (int) round($distanceMeters / 1_000 * 300),
            'start_date_local' => $date->format('Y-m-d\T12:00:00\Z'),
        ];
    }
}
