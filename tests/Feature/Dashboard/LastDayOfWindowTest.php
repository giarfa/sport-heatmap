<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The last day of a window is always a Sunday, and a run on it must count in the cell, the
 * day detail, the week total and the weekly trend like any other day (FR-004).
 *
 * Runs are stored through Eloquent on purpose: the `date` cast writes them as
 * "Y-m-d 00:00:00", which is the format the window bounds were once compared against as TEXT.
 */
class LastDayOfWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->athlete = $this->signInOwner();
    }

    private function runOn(string $date, int $distanceMeters): Activity
    {
        return Activity::factory()->for($this->athlete)->onDate(CarbonImmutable::parse($date))->create([
            'distance_meters' => $distanceMeters,
        ]);
    }

    public function test_a_run_on_the_last_sunday_of_a_past_window_is_in_its_cell_detail_total_and_trend(): void
    {
        $this->travelTo('2026-10-17 09:00:00'); // Saturday
        $this->runOn('2026-10-11', 17_507);

        $html = (string) $this->get('/?to=2026-10-11')->assertOk()->getContent();

        $this->assertStringContainsString('Sunday 11 Oct 2026: 17.5 km, 1 run. Show details', $html);
        $this->assertStringContainsString('<template id="day-2026-10-11">', $html);
        $this->assertStringContainsString('<span class="hm-total-num">17.5</span>', $html);
        $this->assertMatchesRegularExpression('/<title>5 – 11 Oct 2026: 17\.5 km/', $html);
        $this->assertStringNotContainsString('Sunday 11 Oct 2026: rest day', $html);
    }

    public function test_a_run_today_on_a_sunday_is_on_the_current_window(): void
    {
        $this->travelTo('2026-10-11 18:00:00'); // Sunday, the last day of the current window
        $this->runOn('2026-10-11', 17_507);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Sunday 11 Oct 2026: 17.5 km, 1 run. Show details', $html);
        $this->assertStringContainsString('<template id="day-2026-10-11">', $html);
        $this->assertStringContainsString('<span class="hm-total-num">17.5</span>', $html);
    }
}
