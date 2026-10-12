<?php

namespace Tests\Feature\Dashboard;

use __PHP_Incomplete_Class;
use App\Data\Heatmap\HeatmapGrid;
use App\Models\Activity;
use App\Models\User;
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
        $key = "heatmap:grid:{$athlete->id}:{$window->startsOn->toDateString()}:".now()->toDateString();

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
