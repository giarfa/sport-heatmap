<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\Heatmap\HeatmapGridCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The weekly-mileage chart on the real dashboard request: it follows the grid it sits
 * beside, never writes, never calls Strava, and is retired with the grid.
 */
class WeeklyTrendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00'); // Saturday of the week of 31 Aug
    }

    private function runOn(User $athlete, string $date, int $distanceMeters): void
    {
        Activity::factory()->for($athlete)->onDate(CarbonImmutable::parse($date))->create([
            'distance_meters' => $distanceMeters,
        ]);
    }

    /**
     * The trend card's markup, or '' when the page has none.
     */
    private function trendCard(string $url = '/'): string
    {
        $html = $this->get($url)->getContent() ?: '';

        return preg_match('/<section class="card p-4 trend".*?<\/section>/s', $html, $match) === 1 ? $match[0] : '';
    }

    public function test_the_card_sits_in_the_rail_between_the_legend_and_the_sync_card_with_sixteen_named_bars(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-09-03', 12_345); // Thursday of the current week
        $this->runOn($athlete, '2026-08-26', 21_000); // the week before

        $html = $this->actingAs($athlete)->get('/')->getContent() ?: '';

        $legend = strpos($html, 'hm-legend-title');
        $trend = strpos($html, 'class="card p-4 trend"');
        $sync = strpos($html, '>Sync</h3>');

        $this->assertNotFalse($legend);
        $this->assertGreaterThan($legend, $trend);
        $this->assertGreaterThan($trend, $sync);
        $this->assertStringContainsString('aria-label="Legend, weekly trend and sync detail"', $html);

        $card = $this->trendCard();

        $this->assertSame(16, substr_count($card, '<g role="img" aria-label='));
        $this->assertStringContainsString('<title>31 Aug – 6 Sep 2026: 12.3 km so far, partial week</title>', $card);
        $this->assertStringContainsString('<title>24 – 30 Aug 2026: 21.0 km; no 4-week average yet</title>', $card);

        // The bar says what the grid's own row total says.
        $this->assertStringContainsString('<span class="hm-total-num">12.3</span>', $html);
        $this->assertStringContainsString('<span class="hm-total-num">21.0</span>', $html);
    }

    public function test_a_zero_kilometre_week_is_a_stub_and_the_key_names_it(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-09-03', 12_345);

        $card = $this->trendCard();

        $this->assertSame(15, substr_count($card, 'class="trend-zero"'));
        $this->assertSame(1, substr_count($card, '<rect class="trend-bar trend-bar--partial"'));
        $this->assertStringContainsString('0 km week</li>', $card);
        $this->assertStringContainsString('Partial week</li>', $card);
    }

    public function test_the_current_window_has_a_partial_bar_with_no_average_and_the_line_stops_before_it(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-03-31', 10_000); // oldest run: the week of 30 Mar
        $this->runOn($athlete, '2026-09-03', 12_345);

        $card = $this->trendCard();

        $this->assertStringContainsString('<title>31 Aug – 6 Sep 2026: 12.3 km so far, partial week</title>', $card);
        $this->assertSame(1, substr_count($card, 'partial week</title>'));
        $this->assertSame(15, preg_match_all('/<title>[^<]*; 4-week average \d+\.\d km<\/title>/', $card), 'every completed week has an average');
        $this->assertStringContainsString('<polyline class="trend-line"', $card);
        $this->assertStringNotContainsString(' 155,', $card);
    }

    public function test_a_past_window_has_no_partial_bar_and_an_average_on_every_week(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-03-31', 10_000);
        $this->runOn($athlete, '2026-06-10', 15_000);

        $card = $this->trendCard('/?to=2026-08-09');

        $this->assertStringContainsString('20 Apr – 9 Aug 2026', $card);
        $this->assertStringNotContainsString('partial', $card);
        $this->assertSame(16, preg_match_all('/<title>[^<]*km; 4-week average \d+\.\d km<\/title>/', $card));
        $this->assertSame(1, preg_match('/<polyline class="trend-line" points="([^"]+)"/', $card, $line));
        $this->assertCount(16, explode(' ', $line[1]));
    }

    public function test_a_window_with_no_stored_run_says_so_and_draws_no_chart(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-09-01', 8_000);

        $card = $this->trendCard('/?to=2026-06-07'); // ends before the oldest run

        $this->assertStringContainsString('Weekly distance', $card);
        $this->assertStringContainsString('No runs in this window, so there is nothing to chart.', $card);
        $this->assertStringNotContainsString('<svg', $card);
        $this->assertStringNotContainsString('trend-y', $card);
    }

    public function test_the_connect_screen_has_no_trend_card(): void
    {
        $response = $this->get('/');

        $response->assertViewIs('strava.connect');
        $this->assertStringNotContainsString('trend-heading', $response->getContent() ?: '');
    }

    public function test_a_first_sync_in_flight_shows_sixteen_skeleton_bars_hidden_from_assistive_technology(): void
    {
        $athlete = $this->allowlistedAthlete();
        SyncRun::factory()->running()->create();

        $card = $this->actingAs($athlete)->get('/')->assertOk()->getContent() ?: '';
        preg_match('/<section class="card p-4 trend".*?<\/section>/s', $card, $match);
        $card = $match[0] ?? '';

        $this->assertStringContainsString('<div class="trend-chart" aria-hidden="true">', $card);
        $this->assertSame(16, substr_count($card, 'class="skeleton"'));
        $this->assertStringNotContainsString('role="img"', $card);
    }

    public function test_the_weekly_figures_table_has_one_row_per_bar(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-09-03', 12_345);

        $card = $this->trendCard();

        $this->assertStringContainsString('<summary>Weekly figures</summary>', $card);
        $this->assertSame(16, substr_count($card, '<th scope="row">'));
        $this->assertSame(1, substr_count($card, 'trend-table-partial'));
    }

    public function test_a_signed_out_visitor_sees_the_same_card_and_the_request_writes_nothing_and_calls_nobody(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-03-31', 10_000);
        $this->runOn($athlete, '2026-09-03', 12_345);

        $before = [Activity::count(), User::count(), SyncRun::count()];

        $guestCard = $this->trendCard();
        $this->assertGuest();

        $this->assertSame($before, [Activity::count(), User::count(), SyncRun::count()]);
        Http::assertNothingSent();

        $this->assertNotSame('', $guestCard);

        $this->actingAs($athlete);
        $this->assertSame($guestCard, $this->trendCard());
    }

    public function test_a_write_that_retires_the_grid_retires_the_chart_with_it(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-09-03', 12_345);

        $this->assertStringContainsString('12.3 km so far, partial week', $this->trendCard());

        // A run lands without the cache hearing about it: the cached grid, chart included, is served.
        $this->runOn($athlete, '2026-09-04', 10_000);
        $this->assertStringContainsString('12.3 km so far, partial week', $this->trendCard());

        // A sync or a confirmed import ends with forgetAthlete(): the next request shows the new bar.
        $this->app->make(HeatmapGridCache::class)->forgetAthlete($athlete);
        $card = $this->trendCard();

        $this->assertStringContainsString('22.3 km so far, partial week', $card);
        $this->assertStringNotContainsString('12.3 km so far', $card);
    }

    public function test_the_chart_adds_one_query_on_a_cold_cache_and_none_on_a_warm_one(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-09-03', 12_345);

        $cold = $this->queriesOfRequest();
        $warm = $this->queriesOfRequest();

        // Every day range ends on `"activity_date" < ?` (Activity::onDaysBetween): the daily totals,
        // the day detail's runs, the trend's lead-in weeks, and the sync card's run count.
        $this->assertSame(4, $this->countMatching($cold, '"activity_date" < ?'), 'four ranged queries');
        $this->assertSame(2, $this->countMatching($cold, 'sum(distance_meters)'), 'daily totals + lead-in');
        $this->assertSame(0, $this->countMatching($warm, 'sum(distance_meters)'), 'a warm request runs no aggregate');
        $this->assertSame(count($warm) + 3, count($cold));
    }

    /**
     * @return array<int, string>
     */
    private function queriesOfRequest(): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->get('/')->assertOk();

        return $queries;
    }

    /**
     * @param  array<int, string>  $queries
     */
    private function countMatching(array $queries, string $needle): int
    {
        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, strtolower($needle))));
    }
}
