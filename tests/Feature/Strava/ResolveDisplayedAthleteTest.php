<?php

namespace Tests\Feature\Strava;

use App\Models\User;
use App\Support\Strava\ResolveDisplayedAthlete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The resolver decides whose data the public home page shows, so the cases that
 * matter are the ones where it could show the wrong athlete — or touch a token.
 */
class ResolveDisplayedAthleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_an_empty_allowlist_displays_nobody_without_querying(): void
    {
        User::factory()->stravaConnected()->create();
        $this->allowlistAthletes();

        DB::enableQueryLog();

        $this->assertNull($this->resolve());
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_allowlisted_ids_without_a_user_row_display_nobody(): void
    {
        $this->allowlistAthletes('222', '111');

        $this->assertNull($this->resolve());
    }

    public function test_the_first_connected_id_in_configured_order_is_displayed(): void
    {
        User::factory()->stravaConnected()->create(['strava_athlete_id' => '111']);
        User::factory()->stravaConnected()->create(['strava_athlete_id' => '222']);
        $this->allowlistAthletes('222', '111');

        $this->assertSame('222', $this->resolve()?->strava_athlete_id);
    }

    public function test_an_unconnected_first_id_falls_through_to_the_next_connected_one(): void
    {
        User::factory()->stravaConnected()->create(['strava_athlete_id' => '111']);
        $this->allowlistAthletes('222', '111');

        $this->assertSame('111', $this->resolve()?->strava_athlete_id);
    }

    public function test_a_connected_athlete_off_the_allowlist_is_never_displayed(): void
    {
        User::factory()->stravaConnected()->create(['strava_athlete_id' => '333']);
        $this->allowlistAthletes('222');

        $this->assertNull($this->resolve());
    }

    public function test_an_athlete_with_an_expired_token_is_still_displayed_without_its_tokens(): void
    {
        User::factory()->stravaTokenExpired()->create(['strava_athlete_id' => '111']);
        $this->allowlistAthletes('111');

        $athlete = $this->resolve();

        $this->assertNotNull($athlete);
        $this->assertSame('111', $athlete->strava_athlete_id);
        $this->assertArrayNotHasKey('strava_access_token', $athlete->getAttributes());
        $this->assertArrayNotHasKey('strava_refresh_token', $athlete->getAttributes());
        Http::assertNothingSent();
    }

    public function test_a_non_empty_allowlist_costs_exactly_one_query(): void
    {
        User::factory()->stravaConnected()->create(['strava_athlete_id' => '111']);
        User::factory()->stravaConnected()->create(['strava_athlete_id' => '222']);
        $this->allowlistAthletes('333', '222', '111');

        DB::enableQueryLog();

        $this->resolve();

        $this->assertCount(1, DB::getQueryLog());
    }

    private function resolve(): ?User
    {
        return app(ResolveDisplayedAthlete::class)();
    }
}
