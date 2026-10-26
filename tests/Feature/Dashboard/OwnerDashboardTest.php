<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The home page of the signed-in owner: their own map and import status, with the
 * Garmin-only empty state only when there is nothing to show.
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
        $owner = $this->signInOwner();
        $this->runOn($owner, '2026-09-03', 12_345);

        $response = $this->getDashboard();

        $response->assertOk();
        $response->assertViewIs('dashboard');
        $response->assertSee('Thursday 3 Sep 2026: 12.3 km');
        $response->assertSee('<span class="hm-total-num">12.3</span>', escape: false);
        $response->assertSee('>Intensity</h3>', escape: false);
        $response->assertSee('>Import</h3>', escape: false);
        $response->assertSee('Runs in window');
    }

    public function test_the_owner_sees_their_own_runs_and_nobody_elses(): void
    {
        $owner = $this->signInOwner();
        $this->runOn($owner, '2026-09-03', 12_345);
        $someoneElse = User::factory()->create();
        $this->runOn($someoneElse, '2026-09-01', 21_098);

        $response = $this->getDashboard();

        $response->assertViewIs('dashboard');
        $response->assertSee('Thursday 3 Sep 2026: 12.3 km');
        $response->assertDontSee('21.1 km');
    }

    public function test_the_request_writes_nothing_and_sends_no_request(): void
    {
        $owner = $this->signInOwner();
        $this->runOn($owner, '2026-09-03', 12_345);

        $before = $this->databaseSnapshot($owner);

        $this->getDashboard()->assertOk()->assertViewIs('dashboard');

        $this->assertSame($before, $this->databaseSnapshot($owner));
        Http::assertNothingSent();
    }

    public function test_the_grid_is_cached_while_the_import_status_stays_fresh(): void
    {
        $owner = $this->signInOwner();
        $this->runOn($owner, '2026-09-01', 9_000);

        $this->getDashboard()->assertOk();

        // A run lands without the cache hearing about it: the cached grid is served,
        // but the status is read fresh and already names the new import.
        $this->runOn($owner, '2026-09-03', 12_345);
        $this->travelTo(now()->addHours(2));

        $cached = $this->getDashboard();
        $cached->assertDontSee('12.3 km');
        $cached->assertSee('Last import 2 hours ago');
    }

    public function test_the_dashboard_keeps_noindex_and_the_garmin_import_entry(): void
    {
        $owner = $this->signInOwner();
        $this->runOn($owner, '2026-09-03', 12_345);

        $response = $this->getDashboard();

        $response->assertViewIs('dashboard');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
        $response->assertSee('Import Garmin CSV');
        $response->assertSee('href="'.route('garmin.import.create').'"', escape: false);
        $response->assertDontSee('Runs missing from Strava?');
    }

    private function runOn(User $athlete, string $date, int $distanceMeters): void
    {
        Activity::factory()->garminImported()->for($athlete)->onDate(CarbonImmutable::parse($date))->create([
            'distance_meters' => $distanceMeters,
        ]);
    }

    /**
     * Row counts of every table a request could write to, plus the owner's row
     * as stored.
     *
     * @return array<string, mixed>
     */
    private function databaseSnapshot(User $athlete): array
    {
        return [
            'users' => DB::table('users')->count(),
            'activities' => DB::table('activities')->count(),
            'athlete' => (array) DB::table('users')
                ->where('id', $athlete->id)
                ->first(['updated_at']),
        ];
    }

    private function getDashboard(): TestResponse
    {
        return $this->get('/');
    }
}
