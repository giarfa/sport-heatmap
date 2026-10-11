<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The signed-out home page: the displayed athlete's map, read-only, with the
 * connect screen only when nobody is displayable.
 */
class PublicDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00'); // Saturday
    }

    public function test_a_guest_sees_the_displayed_athletes_populated_map(): void
    {
        $athlete = $this->allowlistedAthlete();
        $this->runOn($athlete, '2026-09-03', 12_345);
        SyncRun::factory()->create([
            'started_at' => now()->subHours(6)->subMinute(),
            'finished_at' => now()->subHours(6),
        ]);

        $response = $this->getDashboard();

        $response->assertOk();
        $response->assertViewIs('dashboard');
        $this->assertGuest();
        $response->assertSee('Thursday 3 Sep 2026: 12.3 km');
        $response->assertSee('<span class="hm-total-num">12.3</span>', escape: false);
        $response->assertSee('>Intensity</h3>', escape: false);
        $response->assertSee('Synced 6 hours ago');
        $response->assertSee('>Sync</h3>', escape: false);
        $response->assertSee('Runs in window');
    }

    public function test_a_guest_gets_the_connect_screen_when_no_allowlisted_athlete_has_connected(): void
    {
        $this->allowlistAthletes('10000001');

        $response = $this->getDashboard();

        $response->assertOk();
        $response->assertViewIs('strava.connect');
    }

    public function test_an_athlete_removed_from_the_allowlist_is_never_displayed(): void
    {
        $removedAthlete = User::factory()->stravaConnected()->create();
        $this->runOn($removedAthlete, '2026-09-03', 12_345);

        $this->allowlistAthletes();
        $this->getDashboard()->assertOk()->assertViewIs('strava.connect');

        $displayedAthlete = $this->allowlistedAthlete();
        $this->runOn($displayedAthlete, '2026-09-02', 7_654);

        $response = $this->getDashboard();

        $response->assertViewIs('dashboard');
        $response->assertSee('Wednesday 2 Sep 2026: 7.7 km');
        $response->assertDontSee('12.3 km');
    }

    public function test_the_first_connected_id_in_configured_order_is_displayed(): void
    {
        $athleteA = User::factory()->stravaConnected()->create(['strava_athlete_id' => '111']);
        $athleteB = User::factory()->stravaConnected()->create(['strava_athlete_id' => '222']);
        $this->runOn($athleteA, '2026-09-03', 12_345);
        $this->runOn($athleteB, '2026-09-02', 7_654);
        $this->allowlistAthletes('222', '111');

        $response = $this->getDashboard();

        $response->assertSee('Wednesday 2 Sep 2026: 7.7 km');
        $response->assertDontSee('12.3 km');
    }

    public function test_a_guest_request_writes_nothing_and_sends_no_request_even_with_an_expired_token(): void
    {
        $athlete = $this->allowlistedAthlete(factoryState: 'stravaTokenExpired');
        $this->runOn($athlete, '2026-09-03', 12_345);
        SyncRun::factory()->create();

        $before = $this->databaseSnapshot($athlete);

        $this->getDashboard()->assertOk()->assertViewIs('dashboard');

        $this->assertSame($before, $this->databaseSnapshot($athlete));
        Http::assertNothingSent();
    }

    public function test_a_guest_sees_the_same_sync_warning_as_the_owner(): void
    {
        $this->allowlistedAthlete();
        SyncRun::factory()->failed()->create([
            'started_at' => now()->subHours(2)->subMinute(),
            'finished_at' => now()->subHours(2),
            'error_message' => 'Strava said <script>alert(1)</script>',
        ]);

        $response = $this->getDashboard();

        $response->assertSee('Last sync failed 2 hours ago');
        $response->assertSee('Strava said <script>alert(1)</script>');
        $response->assertDontSee('<script>alert(1)</script>', escape: false);
        $response->assertSee('href="'.route('strava.redirect').'" class="sync-alert-action"', escape: false);
    }

    public function test_guests_and_the_owner_share_the_grid_cache_while_the_sync_status_stays_fresh(): void
    {
        $owner = $this->allowlistedAthlete();
        SyncRun::factory()->create([
            'started_at' => now()->subHours(6)->subMinute(),
            'finished_at' => now()->subHours(6),
        ]);

        $this->actingAs($owner)->getDashboard()->assertOk();
        Auth::guard()->forgetUser();

        $this->runOn($owner, '2026-09-03', 12_345);

        $guestResponse = $this->getDashboard();

        $this->assertGuest();
        $guestResponse->assertDontSee('12.3 km');
        $guestResponse->assertDontSee('role="alert"', escape: false);

        SyncRun::factory()->failed()->create([
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);

        $this->getDashboard()->assertSee('role="alert"', escape: false);
    }

    public function test_the_public_dashboard_keeps_noindex_and_the_strava_attribution(): void
    {
        $this->allowlistedAthlete();

        $response = $this->getDashboard();

        $response->assertViewIs('dashboard');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
        $response->assertSee('Powered by Strava');
    }

    private function runOn(User $athlete, string $date, int $distanceMeters): void
    {
        Activity::factory()->for($athlete)->onDate(CarbonImmutable::parse($date))->create([
            'distance_meters' => $distanceMeters,
        ]);
    }

    /**
     * Row counts of every table a request could write to, plus the displayed
     * athlete's raw token columns — read through the query builder so the
     * encrypted values are compared as stored, without decrypting them.
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
