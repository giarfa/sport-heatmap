<?php

namespace Tests\Feature\Dashboard;

use __PHP_Incomplete_Class;
use App\Data\Heatmap\DayRun;
use App\Data\Heatmap\HeatmapGrid;
use App\Data\Heatmap\TrendBar;
use App\Data\Heatmap\TrendTick;
use App\Models\Activity;
use App\Models\User;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapGridCache;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use stdClass;
use Tests\TestCase;

/**
 * The grid cache: a hit skips the aggregate query, a successful sync forgets
 * it, and the calendar day is part of the key so it cannot survive midnight.
 *
 * The suite's array store does not serialize, so the round-trip tests switch to
 * the database store — the one `.env.example` ships — where a class missing
 * from `cache.serializable_classes` comes back as __PHP_Incomplete_Class.
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

    public function test_a_grid_cached_under_the_previous_key_version_is_not_served(): void
    {
        $athlete = $this->allowlistedAthlete();
        $window = HeatmapWindow::current();
        $suffix = "{$athlete->id}:g0:{$window->startsOn->toDateString()}:".now()->toDateString();
        $builds = 0;

        // What the deploy before US-015 left behind: a grid built while the last day was missing.
        $this->rememberWindow($athlete, $window, $builds);
        Cache::put("heatmap:grid:v4:{$suffix}", Cache::pull("heatmap:grid:v5:{$suffix}"), 3_600);

        $this->rememberWindow($athlete, $window, $builds);

        $this->assertSame(2, $builds, 'The v4 entry is ignored and the grid is rebuilt under v5.');
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
        /*
         * The token must outlive the jump to the next day: an expired one makes the
         * dashboard refresh it over HTTP, the stray request is rejected, and the page
         * becomes a 500. With APP_DEBUG on, that debug page happens to contain the
         * asserted distance, so the failure stays hidden until debug is off.
         */
        $athlete = $this->allowlistedAthlete(['strava_token_expires_at' => now()->addDays(2)]);
        $this->actingAs($athlete);
        $this->getDashboard()->assertOk();

        Activity::factory()->for($athlete)->onDate(HeatmapWindow::current()->endsOn->subDays(2))->create([
            'distance_meters' => 9_000,
        ]);

        $this->travelTo('2026-09-06 09:00:00'); // Sunday — same ISO week, different calendar day

        $html = $this->getDashboard()->getContent() ?: '';

        $this->assertStringContainsString('9.0 km', $html);
    }

    public function test_a_serializing_store_serves_the_cached_grid_to_the_signed_in_athlete(): void
    {
        $this->useTheDatabaseCacheStore();
        $athlete = $this->athleteWithOneRun();
        $this->actingAs($athlete);

        $this->getDashboard()->assertOk()->assertSee('9.0 km');

        $this->addALaterRun($athlete);

        $this->getDashboard()
            ->assertOk()
            ->assertSee('9.0 km')
            ->assertDontSee('7.3 km');
    }

    public function test_a_serializing_store_serves_the_cached_grid_to_a_guest(): void
    {
        $this->useTheDatabaseCacheStore();
        $athlete = $this->athleteWithOneRun();

        $this->getDashboard()->assertOk()->assertSee('9.0 km');

        $this->addALaterRun($athlete);

        $this->getDashboard()
            ->assertOk()
            ->assertSee('9.0 km')
            ->assertDontSee('7.3 km');
    }

    public function test_a_cached_entry_that_cannot_be_restored_is_rebuilt(): void
    {
        $this->useTheDatabaseCacheStore();
        $athlete = $this->athleteWithOneRun();
        $window = HeatmapWindow::current();
        $key = "heatmap:grid:v5:{$athlete->id}:g0:{$window->startsOn->toDateString()}:".now()->toDateString();

        Cache::put($key, new stdClass, 3_600);
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, Cache::get($key));

        $this->getDashboard()->assertOk()->assertSee('9.0 km');

        $this->assertInstanceOf(HeatmapGrid::class, Cache::get($key));
    }

    public function test_a_cached_grid_whose_nested_value_cannot_be_restored_is_rebuilt(): void
    {
        $this->useTheDatabaseCacheStore();
        $athlete = $this->athleteWithOneRun();

        $this->getDashboard()->assertOk()->assertSee('9.0 km');

        // A DayCellState case that no longer exists — what a renamed case leaves
        // behind in a grid cached before the deploy. unserialize() throws on it.
        // The database store keeps the serialized value base64-encoded.
        $row = DB::table('cache')->where('key', 'like', '%heatmap:grid:%')->sole();
        $payload = base64_decode($row->value, true) ?: '';
        $this->assertStringContainsString('DayCellState:Ran"', $payload);
        DB::table('cache')->where('key', $row->key)->update([
            'value' => base64_encode(str_replace('DayCellState:Ran"', 'DayCellState:Xyz"', $payload)),
        ]);

        $this->addALaterRun($athlete);

        $this->getDashboard()
            ->assertOk()
            ->assertSee('9.0 km')
            ->assertSee('7.3 km');
    }

    public function test_the_days_runs_survive_a_serializing_store_inside_the_cached_grid(): void
    {
        $this->useTheDatabaseCacheStore();
        $athlete = $this->athleteWithOneRun();
        $window = HeatmapWindow::current();

        $this->getDashboard()->assertOk();

        $cached = Cache::get("heatmap:grid:v5:{$athlete->id}:g0:{$window->startsOn->toDateString()}:".now()->toDateString());
        $this->assertInstanceOf(HeatmapGrid::class, $cached);

        $ranCells = collect($cached->weeks)->flatMap(fn ($week) => $week->days)->filter(fn ($cell) => $cell->hasDetail());
        $this->assertCount(1, $ranCells);
        $run = $ranCells->first()->runs[0];
        $this->assertInstanceOf(DayRun::class, $run);
        $this->assertSame('9.0 km', $run->kilometres());
        $this->assertInstanceOf(TrendBar::class, $cached->trend->bars[0]);
        $this->assertInstanceOf(TrendTick::class, $cached->trend->ticks[0]);
    }

    public function test_a_grid_cached_before_the_day_detail_existed_is_never_read(): void
    {
        $this->useTheDatabaseCacheStore();
        $athlete = $this->athleteWithOneRun();
        $window = HeatmapWindow::current();

        // What the previous deploy left behind: an old-shape entry under the old key.
        $oldKey = "heatmap:grid:{$athlete->id}:{$window->startsOn->toDateString()}:".now()->toDateString();
        Cache::put($oldKey, new stdClass, 3_600);

        $this->getDashboard()->assertOk()->assertSee('9.0 km');

        $this->assertTrue(Cache::has("heatmap:grid:v5:{$athlete->id}:g0:{$window->startsOn->toDateString()}:".now()->toDateString()));
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, Cache::get($oldKey), 'The old entry is left to expire, untouched.');
    }

    public function test_a_successful_sync_retires_a_cached_past_window_as_well_as_the_current_one(): void
    {
        $athlete = $this->allowlistedAthlete();
        $current = HeatmapWindow::current();
        $pastWindow = $current->previous()->previous();
        $builds = 0;

        $this->rememberWindow($athlete, $current, $builds);
        $this->rememberWindow($athlete, $pastWindow, $builds);
        $this->rememberWindow($athlete, $pastWindow, $builds);
        $this->assertSame(2, $builds, 'The second read of the past window is a hit.');

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([$this->syncedActivityPayload()]),
        ]);
        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->rememberWindow($athlete, $current, $builds);
        $this->rememberWindow($athlete, $pastWindow, $builds);

        $this->assertSame(4, $builds, 'Both windows are rebuilt after the sync.');
    }

    public function test_a_failed_sync_leaves_a_cached_past_window_in_place(): void
    {
        $athlete = $this->allowlistedAthlete();
        $pastWindow = HeatmapWindow::current()->previous();
        $builds = 0;

        $this->rememberWindow($athlete, $pastWindow, $builds);

        Http::fake(['www.strava.com/api/v3/athlete/activities*' => Http::response(['message' => 'Server Error'], 500)]);
        $this->artisan('strava:sync')->assertExitCode(Command::FAILURE);

        $this->rememberWindow($athlete, $pastWindow, $builds);

        $this->assertSame(1, $builds);
    }

    public function test_forgetting_one_athlete_leaves_another_athletes_cached_window_untouched(): void
    {
        $this->useTheDatabaseCacheStore();
        $first = $this->allowlistedAthlete();
        $second = $this->allowlistedAthlete();
        $pastWindow = HeatmapWindow::current()->previous();
        $builds = 0;

        $this->rememberWindow($first, $pastWindow, $builds);
        $this->rememberWindow($second, $pastWindow, $builds);

        $this->app->make(HeatmapGridCache::class)->forgetAthlete($first);

        $this->rememberWindow($second, $pastWindow, $builds);
        $this->assertSame(2, $builds, 'The other athlete still hits the cache.');

        $this->rememberWindow($first, $pastWindow, $builds);
        $this->assertSame(3, $builds, 'The forgotten athlete is rebuilt.');
    }

    public function test_forgetting_an_athlete_twice_keeps_retiring_the_windows_cached_in_between(): void
    {
        $this->useTheDatabaseCacheStore();
        $athlete = $this->allowlistedAthlete();
        $pastWindow = HeatmapWindow::current()->previous();
        $cache = $this->app->make(HeatmapGridCache::class);
        $builds = 0;

        $cache->forgetAthlete($athlete);
        $this->rememberWindow($athlete, $pastWindow, $builds);
        $cache->forgetAthlete($athlete);
        $this->rememberWindow($athlete, $pastWindow, $builds);

        $this->assertSame(2, $builds);
    }

    public function test_the_cache_restores_no_class_outside_the_allowlist(): void
    {
        $this->useTheDatabaseCacheStore();

        Cache::put('serialization-probe', new stdClass, 60);

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, Cache::get('serialization-probe'));
    }

    /**
     * The first test of a run migrates inside setUp(), which already resolves
     * the default store; forgetting it makes HeatmapGridCache get the database
     * store whatever the test order.
     */
    private function useTheDatabaseCacheStore(): void
    {
        Config::set('cache.default', 'database');
        $this->app->forgetInstance('cache.store');
    }

    private function athleteWithOneRun(): User
    {
        $athlete = $this->allowlistedAthlete();

        Activity::factory()->for($athlete)->onDate(HeatmapWindow::current()->endsOn->subDays(3))->create([
            'distance_meters' => 9_000,
        ]);

        return $athlete;
    }

    private function addALaterRun(User $athlete): void
    {
        Activity::factory()->for($athlete)->onDate(HeatmapWindow::current()->endsOn->subDays(2))->create([
            'distance_meters' => 7_300,
        ]);
    }

    /**
     * Read a window through the grid cache the way the dashboard does, counting how
     * often the grid had to be built — a hit leaves the count alone.
     */
    private function rememberWindow(User $athlete, HeatmapWindow $window, int &$builds): HeatmapGrid
    {
        return $this->app->make(HeatmapGridCache::class)->remember(
            $athlete,
            $window,
            now(),
            function () use ($window, &$builds): HeatmapGrid {
                $builds++;

                return $this->app->make(HeatmapBuilder::class)($window, [], now());
            },
        );
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
