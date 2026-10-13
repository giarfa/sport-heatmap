<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Previous / Next through HTTP (US-013, FR-019): which window `?to=` names, which controls are
 * links, what the page says about the window, and that none of it writes or calls Strava.
 *
 * "Today" is Saturday 5 Sep 2026, so the current window is 18 May – 6 Sep 2026 and one
 * Previous step back is 20 Apr – 9 Aug 2026.
 */
class WindowNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00');
    }

    public function test_the_home_page_opens_on_the_current_window_with_next_disabled(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-03-02', 10_000);
        $this->runOn($athlete, '2026-09-03', 12_000);

        $response = $this->actingAs($athlete)->get('/')->assertOk();

        $response->assertSee('Last 16 weeks');
        $response->assertSee('18 May – 6 Sep 2026');
        $response->assertSee('<title>Dashboard — Sport Heatmap</title>', escape: false);

        $controls = $this->controls($response);
        $this->assertSame(['link', route('dashboard', ['to' => '2026-08-09'])], $controls['Previous 4 weeks']);
        $this->assertSame(['disabled button', null], $controls['Next 4 weeks']);
    }

    public function test_a_past_window_shows_that_periods_runs_totals_and_range(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-07-02', 10_000);
        $this->runOn($athlete, '2026-09-03', 12_000);

        $response = $this->actingAs($athlete)->get('/?to=2026-08-09')->assertOk();

        $response->assertSee('16 weeks to 9 Aug');
        $response->assertSee('20 Apr – 9 Aug 2026');
        $response->assertSee('<title>16 weeks to 9 Aug — Sport Heatmap</title>', escape: false);
        $response->assertSee('Thursday 2 Jul 2026: 10.0 km');
        $response->assertSee('<span class="hm-total-num">10.0</span>', escape: false);
        $response->assertDontSee('12.0 km');
        $response->assertDontSee('hm-cell--future');
        $response->assertDontSee('hm-partial');
    }

    public function test_next_steps_four_weeks_forward_and_lands_on_the_plain_home_url_at_the_current_window(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-03-02', 10_000);

        $eightWeeksBack = $this->controls($this->actingAs($athlete)->get('/?to=2026-07-12'));
        $this->assertSame(['link', route('dashboard', ['to' => '2026-08-09'])], $eightWeeksBack['Next 4 weeks']);
        $this->assertSame(['link', route('dashboard', ['to' => '2026-06-14'])], $eightWeeksBack['Previous 4 weeks']);

        $fourWeeksBack = $this->controls($this->actingAs($athlete)->get('/?to=2026-08-09'));
        $this->assertSame(['link', route('dashboard')], $fourWeeksBack['Next 4 weeks']);
    }

    public function test_previous_is_a_link_only_while_an_older_run_is_stored(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-05-18', 10_000); // the first day of the current window

        $this->assertSame('disabled button', $this->controls($this->actingAs($athlete)->get('/'))['Previous 4 weeks'][0]);

        $this->runOn($athlete, '2026-05-17', 5_000); // the day before it

        $this->assertSame('link', $this->controls($this->actingAs($athlete)->get('/'))['Previous 4 weeks'][0]);
    }

    public function test_previous_is_disabled_on_the_window_that_holds_the_oldest_run(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-07-02', 10_000);
        $this->runOn($athlete, '2026-09-03', 12_000);

        $controls = $this->controls($this->actingAs($athlete)->get('/?to=2026-08-09'));

        $this->assertSame('disabled button', $controls['Previous 4 weeks'][0]);
        $this->assertSame('link', $controls['Next 4 weeks'][0]);
    }

    public function test_both_controls_are_disabled_when_no_run_is_stored(): void
    {
        $athlete = $this->allowlistedAthlete();

        $controls = $this->controls($this->actingAs($athlete)->get('/'));

        $this->assertSame('disabled button', $controls['Previous 4 weeks'][0]);
        $this->assertSame('disabled button', $controls['Next 4 weeks'][0]);
    }

    public function test_an_empty_past_window_says_nothing_is_stored_there(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-02-02', 10_000);
        $this->runOn($athlete, '2026-09-03', 12_000);

        $this->actingAs($athlete)->get('/?to=2026-08-09')
            ->assertOk()
            ->assertSee('No runs stored in these 16 weeks.');

        $this->actingAs($athlete)->get('/')
            ->assertOk()
            ->assertDontSee('No runs stored in these 16 weeks.');
    }

    public function test_each_window_gets_its_own_legend_computed_from_its_own_days(): void
    {
        $athlete = $this->allowlistedAthlete();
        foreach (['2026-06-02' => 5_000, '2026-06-09' => 8_000, '2026-06-16' => 12_000, '2026-06-23' => 20_000] as $date => $meters) {
            $this->runOn($athlete, $date, $meters);
        }
        foreach (['2026-08-18' => 6_000, '2026-08-25' => 9_000, '2026-09-01' => 14_000, '2026-09-02' => 25_000] as $date => $meters) {
            $this->runOn($athlete, $date, $meters);
        }

        $past = $this->legend($this->actingAs($athlete)->get('/?to=2026-08-09'));
        $current = $this->legend($this->actingAs($athlete)->get('/'));

        $this->assertNotSame('', $past);
        $this->assertNotSame($past, $current);
    }

    public function test_the_sync_status_is_the_same_on_every_window(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-07-02', 10_000);
        $this->runOn($athlete, '2026-09-03', 12_000);
        SyncRun::factory()->create([
            'started_at' => now()->subHours(6)->subMinute(),
            'finished_at' => now()->subHours(6),
        ]);

        $current = $this->actingAs($athlete)->get('/');
        $past = $this->actingAs($athlete)->get('/?to=2026-08-09');

        $currentChip = $this->chip($current);
        $this->assertStringContainsString('Synced 6 hours ago', $currentChip);
        $this->assertSame($currentChip, $this->chip($past));
        $this->assertSame(
            $this->between($current, '<div class="sync-detail">', '</dl>'),
            $this->between($past, '<div class="sync-detail">', '</dl>'),
        );
    }

    public function test_windows_are_cached_separately(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-05-12', 10_000); // only in the earlier window
        $this->runOn($athlete, '2026-09-03', 12_000); // only in the current one

        $this->actingAs($athlete)->get('/?to=2026-08-09')->assertSee('Tuesday 12 May 2026: 10.0 km');
        $current = $this->actingAs($athlete)->get('/')->assertOk();

        $current->assertSee('12.0 km');
        $current->assertDontSee('Tuesday 12 May 2026');
    }

    public function test_only_windows_that_can_hold_a_run_are_cached(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-07-02', 10_000);
        $keyOf = fn (string $to): string => "heatmap:grid:v4:{$athlete->id}:g0:"
            .HeatmapWindow::fromRequestedEnd($to, now())->startsOn->toDateString().':2026-09-05';

        $this->actingAs($athlete)->get('/?to=2026-08-09')->assertOk();
        $this->actingAs($athlete)->get('/?to=2020-01-05')->assertOk()->assertSee('No runs stored in these 16 weeks.');
        $this->actingAs($athlete)->get('/?to=0001-01-07')->assertOk();

        $this->assertTrue(Cache::has($keyOf('2026-08-09')));
        $this->assertFalse(Cache::has($keyOf('2020-01-05')), 'A window before the oldest run is built, not cached.');
        $this->assertFalse(Cache::has($keyOf('0001-01-07')));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function windowRequestProvider(): array
    {
        return [
            'an impossible date' => ['?to=2026-02-31', 'Last 16 weeks', '18 May – 6 Sep 2026'],
            'free text' => ['?to=garbage', 'Last 16 weeks', '18 May – 6 Sep 2026'],
            'an array' => ['?to[]=x', 'Last 16 weeks', '18 May – 6 Sep 2026'],
            'an empty value' => ['?to=', 'Last 16 weeks', '18 May – 6 Sep 2026'],
            'a future Sunday' => ['?to=2026-12-27', 'Last 16 weeks', '18 May – 6 Sep 2026'],
            'the current Sunday' => ['?to=2026-09-06', 'Last 16 weeks', '18 May – 6 Sep 2026'],
            'a mid-week date of the current week' => ['?to=2026-09-02', 'Last 16 weeks', '18 May – 6 Sep 2026'],
            'a Wednesday of an earlier week' => ['?to=2026-08-05', '16 weeks to 9 Aug', '20 Apr – 9 Aug 2026'],
        ];
    }

    #[DataProvider('windowRequestProvider')]
    public function test_a_requested_end_names_its_window_and_a_bad_one_renders_the_current_window(string $query, string $heading, string $range): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-09-03', 12_000);

        $this->actingAs($athlete)->get('/'.$query)
            ->assertOk()
            ->assertSee($heading)
            ->assertSee($range);
    }

    public function test_a_signed_out_visitor_navigates_the_same_way_and_nothing_is_written_or_sent(): void
    {
        $athlete = $this->allowlistedAthlete(factoryState: 'stravaTokenExpired');
        $this->runOn($athlete, '2026-03-02', 10_000);
        $this->runOn($athlete, '2026-07-02', 11_000);
        SyncRun::factory()->create();

        $before = $this->databaseSnapshot($athlete);

        $response = $this->get('/?to=2026-08-09')->assertOk()->assertViewIs('dashboard');

        $this->assertGuest();
        $response->assertSee('20 Apr – 9 Aug 2026');
        $response->assertSee('Thursday 2 Jul 2026: 11.0 km');
        $controls = $this->controls($response);
        $this->assertSame(['link', route('dashboard', ['to' => '2026-07-12'])], $controls['Previous 4 weeks']);
        $this->assertSame(['link', route('dashboard')], $controls['Next 4 weeks']);

        $this->assertSame($before, $this->databaseSnapshot($athlete));
        Http::assertNothingSent();
    }

    /**
     * The controls by accessible name, as [kind, href]: a link with its href, or a disabled
     * button with none. The name is the text with the decorative arrows removed.
     *
     * @return array<string, array{string, string|null}>
     */
    private function controls(TestResponse $response): array
    {
        $html = (string) $response->getContent();
        preg_match('/<nav class="win-nav[^"]*" aria-label="Window">(.*?)<\/nav>/s', $html, $nav);
        $this->assertNotEmpty($nav, 'The page carries the window navigation.');

        preg_match_all('/<(a|button)\b([^>]*class="win-btn[^"]*"[^>]*)>(.*?)<\/\1>/s', $nav[1], $matches, PREG_SET_ORDER);

        $controls = [];

        foreach ($matches as [, $tag, $attributes, $inner]) {
            $withoutArrows = preg_replace('/<span aria-hidden="true">.*?<\/span>/s', '', $inner);
            $name = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $withoutArrows)));

            if ($tag === 'a') {
                preg_match('/href="([^"]*)"/', $attributes, $href);
                $controls[$name] = ['link', html_entity_decode($href[1])];

                continue;
            }

            $this->assertStringContainsString('disabled', $attributes);
            $this->assertStringContainsString('type="button"', $attributes);
            $controls[$name] = ['disabled button', null];
        }

        $this->assertEqualsCanonicalizing(['Previous 4 weeks', 'Next 4 weeks'], array_keys($controls));

        return $controls;
    }

    private function chip(TestResponse $response): string
    {
        preg_match('/<span class="sync-chip".*?<\/span>\s*[^<]+<\/span>/s', (string) $response->getContent(), $chip);
        $this->assertNotEmpty($chip, 'The page carries the sync chip.');

        return $chip[0];
    }

    private function legend(TestResponse $response): string
    {
        return $this->between($response, '<ul class="hm-legend-list">', '</ul>');
    }

    private function between(TestResponse $response, string $start, string $end): string
    {
        $html = (string) $response->getContent();
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "The page carries {$start}.");
        $to = strpos($html, $end, $from);
        $this->assertNotFalse($to);

        return substr($html, $from, $to - $from + strlen($end));
    }

    private function runOn(User $athlete, string $date, int $distanceMeters): void
    {
        Activity::factory()->for($athlete)->onDate(CarbonImmutable::parse($date))->create([
            'distance_meters' => $distanceMeters,
        ]);
    }

    /**
     * Row counts of every table a request could write to, plus the athlete's raw token
     * columns, read through the query builder so the encrypted values are compared as stored.
     *
     * @return array<string, mixed>
     */
    private function databaseSnapshot(User $athlete): array
    {
        return [
            'users' => DB::table('users')->count(),
            'activities' => DB::table('activities')->count(),
            'sync_runs' => DB::table('sync_runs')->count(),
            'athlete' => (array) DB::table('users')
                ->where('id', $athlete->id)
                ->first(['updated_at', 'strava_access_token', 'strava_refresh_token', 'strava_token_expires_at']),
        ];
    }
}
