<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The rendered dashboard: grid shape, formatting, accessibility markup and
 * athlete isolation. US-004's Demonstrates line: 112 day cells, 16 week totals.
 */
class HeatmapGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00'); // Saturday, matches the mockup's "today"
    }

    public function test_the_grid_renders_112_day_cells_and_16_week_totals(): void
    {
        $html = $this->dashboardHtml($this->allowlistedAthlete());

        $this->assertSame(112, substr_count($html, 'class="hm-td"'));
        $this->assertSame(16, substr_count($html, 'class="hm-total"'));
    }

    public function test_a_known_days_distance_renders_in_kilometres_to_one_decimal(): void
    {
        $athlete = $this->allowlistedAthlete();
        Activity::factory()->for($athlete)->onDate(HeatmapWindow::current()->endsOn->subDay())->create([
            'distance_meters' => 8_200,
        ]);

        $html = $this->dashboardHtml($athlete);

        $this->assertStringContainsString('8.2 km', $html);
    }

    public function test_a_known_rest_day_renders_the_exact_accessible_name(): void
    {
        $html = $this->dashboardHtml($this->allowlistedAthlete());

        $this->assertStringContainsString('rest day, 0 km', $html);
    }

    public function test_a_future_day_in_the_current_week_renders_not_yet_and_the_dashed_treatment(): void
    {
        $html = $this->dashboardHtml($this->allowlistedAthlete());

        $this->assertStringContainsString('not yet', $html);
        $this->assertStringContainsString('hm-cell--future', $html);
    }

    public function test_the_current_weeks_total_is_partial_and_a_completed_weeks_is_not(): void
    {
        $html = $this->dashboardHtml($this->allowlistedAthlete());

        $this->assertSame(1, substr_count($html, 'hm-partial'));
    }

    public function test_the_grid_has_the_required_accessibility_markup(): void
    {
        $html = $this->dashboardHtml($this->allowlistedAthlete());

        $this->assertStringContainsString('<caption class="sr-only">', $html);
        $this->assertSame(8, substr_count($html, 'scope="col"'));
        $this->assertSame(16, substr_count($html, 'scope="row"'));
    }

    public function test_the_skip_link_and_strava_attribution_are_present(): void
    {
        $html = $this->dashboardHtml($this->allowlistedAthlete());

        $this->assertStringContainsString('href="#main"', $html);
        $this->assertStringContainsString('Powered by Strava', $html);
        $this->assertStringContainsString('https://www.strava.com', $html);
    }

    public function test_neither_mi_nor_miles_appears_anywhere(): void
    {
        $html = $this->dashboardHtml($this->allowlistedAthlete());

        $this->assertDoesNotMatchRegularExpression('/\bmi\b/', $html);
        $this->assertStringNotContainsString('miles', $html);
    }

    public function test_another_athletes_activities_do_not_appear_in_this_athletes_grid(): void
    {
        $athlete = $this->allowlistedAthlete();
        $otherAthlete = User::factory()->stravaConnected()->create();

        Activity::factory()->for($otherAthlete)->onDate(HeatmapWindow::current()->endsOn->subDay())->create([
            'distance_meters' => 30_000,
        ]);

        $html = $this->dashboardHtml($athlete);

        $this->assertStringNotContainsString('30.0 km', $html);
    }

    public function test_an_athlete_with_no_activities_renders_the_full_grid_not_a_blank_page(): void
    {
        $response = $this->actingAs($this->allowlistedAthlete())->get('/');

        $response->assertOk();
        $html = $response->getContent() ?: '';

        $this->assertSame(112, substr_count($html, 'class="hm-td"'));
    }

    private function dashboardHtml(User $athlete): string
    {
        $response = $this->actingAs($athlete)->get('/');
        $response->assertOk();

        return $response->getContent() ?: '';
    }
}
