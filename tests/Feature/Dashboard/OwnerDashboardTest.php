<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The home page of the signed-in owner (US-020): their own map, runs of either
 * source, with the connect screen only when there is nothing to show.
 */
class OwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00'); // Saturday
    }

    public function test_the_owner_sees_their_populated_map(): void
    {
        $owner = $this->signInOwner(stravaConnected: true);
        $this->runOn($owner, '2026-09-03', 12_345);
        SyncRun::factory()->create([
            'started_at' => now()->subHours(6)->subMinute(),
            'finished_at' => now()->subHours(6),
        ]);

        $response = $this->getDashboard();

        $response->assertOk();
        $response->assertViewIs('dashboard');
        $response->assertSee('Thursday 3 Sep 2026: 12.3 km');
        $response->assertSee('<span class="hm-total-num">12.3</span>', escape: false);
        $response->assertSee('>Intensity</h3>', escape: false);
        $response->assertSee('Synced 6 hours ago');
        $response->assertSee('>Sync</h3>', escape: false);
        $response->assertSee('Runs in window');
    }

    public function test_an_owner_with_no_run_and_no_strava_connection_gets_the_connect_screen(): void
    {
        $this->signInOwner();

        $response = $this->getDashboard();

        $response->assertOk();
        $response->assertViewIs('strava.connect');
    }

    public function test_the_owner_sees_runs_of_either_source_and_nobody_elses(): void
    {
        $owner = $this->signInOwner(stravaConnected: true);
        $this->runOn($owner, '2026-09-03', 12_345);
        Activity::factory()->garminImported()->for($owner)->onDate(CarbonImmutable::parse('2026-09-02'))->create([
            'distance_meters' => 7_654,
        ]);
        $someoneElse = User::factory()->stravaConnected()->create();
        $this->runOn($someoneElse, '2026-09-01', 21_098);

        $response = $this->getDashboard();

        $response->assertViewIs('dashboard');
        $response->assertSee('Thursday 3 Sep 2026: 12.3 km');
        $response->assertSee('Wednesday 2 Sep 2026: 7.7 km');
        $response->assertDontSee('21.1 km');
    }

    public function test_the_owner_sees_the_stale_sync_warning(): void
    {
        $owner = $this->signInOwner(stravaConnected: true);
        $this->runOn($owner, '2026-09-03', 12_345);
        SyncRun::factory()->create([
            'started_at' => now()->subHours(50)->subMinute(),
            'finished_at' => now()->subHours(50),
        ]);

        $this->getDashboard()
            ->assertViewIs('dashboard')
            ->assertSee('role="alert"', escape: false)
            ->assertSee('Last successful sync was 2 days ago');
    }

    public function test_the_failed_sync_message_is_escaped_and_offers_the_reconnect_link(): void
    {
        $owner = $this->signInOwner(stravaConnected: true);
        $this->runOn($owner, '2026-09-03', 12_345);
        SyncRun::factory()->failed()->create([
            'started_at' => now()->subHours(2)->subMinute(),
            'finished_at' => now()->subHours(2),
            'error_message' => 'Strava said <script>alert(1)</script>',
        ]);

        $response = $this->getDashboard();

        $response->assertViewIs('dashboard');
        $response->assertSee('Last sync failed 2 hours ago');
        $response->assertSee('Strava said <script>alert(1)</script>');
        $response->assertDontSee('<script>alert(1)</script>', escape: false);
        $response->assertSee('href="'.route('strava.redirect').'" class="sync-alert-action"', escape: false);
    }

    public function test_the_request_writes_nothing_and_sends_no_request_even_with_an_expired_token(): void
    {
        $owner = $this->signInOwner(['strava_token_expires_at' => now()->subHour()], stravaConnected: true);
        $this->runOn($owner, '2026-09-03', 12_345);
        SyncRun::factory()->create();

        $before = $this->databaseSnapshot($owner);

        $this->getDashboard()->assertOk()->assertViewIs('dashboard');

        $this->assertSame($before, $this->databaseSnapshot($owner));
        Http::assertNothingSent();
    }

    public function test_the_grid_is_cached_while_the_sync_status_stays_fresh(): void
    {
        $owner = $this->signInOwner(stravaConnected: true);
        SyncRun::factory()->create([
            'started_at' => now()->subHours(6)->subMinute(),
            'finished_at' => now()->subHours(6),
        ]);

        $this->getDashboard()->assertOk();

        // A run lands without the cache hearing about it: the cached grid is served.
        $this->runOn($owner, '2026-09-03', 12_345);

        $cached = $this->getDashboard();
        $cached->assertDontSee('12.3 km');
        $cached->assertDontSee('role="alert"', escape: false);

        SyncRun::factory()->failed()->create([
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);

        $this->getDashboard()->assertSee('role="alert"', escape: false);
    }

    public function test_the_dashboard_keeps_noindex_the_strava_attribution_and_the_garmin_import_entry(): void
    {
        $this->signInOwner(stravaConnected: true);

        $response = $this->getDashboard();

        $response->assertViewIs('dashboard');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
        $response->assertSee('Powered by Strava');
        $response->assertSee('Import Garmin CSV');
        $response->assertSee('href="'.route('garmin.import.create').'"', escape: false);
    }

    public function test_the_dashboard_offers_no_strava_sign_in_link(): void
    {
        $owner = $this->signInOwner(stravaConnected: true);
        $this->runOn($owner, '2026-09-03', 12_345);

        $response = $this->getDashboard();

        $response->assertDontSee('Owner?');
        $response->assertDontSee('Connect with Strava');
    }

    private function runOn(User $athlete, string $date, int $distanceMeters): void
    {
        Activity::factory()->for($athlete)->onDate(CarbonImmutable::parse($date))->create([
            'distance_meters' => $distanceMeters,
        ]);
    }

    /**
     * Row counts of every table a request could write to, plus the owner's raw
     * token columns — read through the query builder so the encrypted values are
     * compared as stored, without decrypting them.
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

    private function getDashboard(): TestResponse
    {
        return $this->get('/');
    }
}
