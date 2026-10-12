<?php

namespace Tests\Feature\Strava;

use App\Models\Activity;
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

    public function test_an_empty_allowlist_with_no_activities_displays_nobody_in_one_query(): void
    {
        User::factory()->stravaConnected()->create();
        $this->allowlistAthletes();

        DB::enableQueryLog();

        $this->assertNull($this->resolve());
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_a_blank_allowlist_falls_back_to_the_lowest_id_athlete_with_activities(): void
    {
        User::factory()->stravaConnected()->create(['strava_athlete_id' => '111']);
        $firstWithActivities = User::factory()->stravaConnected()->create(['strava_athlete_id' => '222']);
        $laterWithActivities = User::factory()->stravaConnected()->create(['strava_athlete_id' => '333']);
        Activity::factory()->for($laterWithActivities)->create();
        Activity::factory()->for($firstWithActivities)->create();
        $this->allowlistAthletes();

        $this->assertSame($firstWithActivities->id, $this->resolve()?->id);
    }

    public function test_an_unconnected_allowlist_falls_back_to_a_stored_athlete_with_activities(): void
    {
        $storedAthlete = User::factory()->stravaConnected()->create(['strava_athlete_id' => '333']);
        Activity::factory()->for($storedAthlete)->create();
        $this->allowlistAthletes('222', '111');

        $this->assertSame('333', $this->resolve()?->strava_athlete_id);
    }

    public function test_a_connected_allowlisted_athlete_beats_an_older_athlete_with_activities(): void
    {
        $olderAthlete = User::factory()->stravaConnected()->create(['strava_athlete_id' => '333']);
        Activity::factory()->for($olderAthlete)->create();
        User::factory()->stravaConnected()->create(['strava_athlete_id' => '111']);
        $this->allowlistAthletes('111');

        $this->assertSame('111', $this->resolve()?->strava_athlete_id);
    }

    public function test_a_garmin_only_athlete_qualifies_for_the_fallback(): void
    {
        $garminAthlete = User::factory()->stravaConnected()->create(['strava_athlete_id' => '333']);
        Activity::factory()->for($garminAthlete)->garminImported()->create();
        $this->allowlistAthletes();

        $this->assertSame('333', $this->resolve()?->strava_athlete_id);
    }

    public function test_the_fallback_athlete_is_resolved_without_its_tokens_or_any_request(): void
    {
        $storedAthlete = User::factory()->stravaTokenExpired()->create(['strava_athlete_id' => '333']);
        Activity::factory()->for($storedAthlete)->create();
        $this->allowlistAthletes();

        $athlete = $this->resolve();

        $this->assertNotNull($athlete);
        $this->assertSame(['id', 'strava_athlete_id'], array_keys($athlete->getAttributes()));
        Http::assertNothingSent();
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

    public function test_an_athlete_off_the_allowlist_without_activities_is_never_displayed(): void
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

    public function test_an_unconnected_allowlist_costs_two_queries(): void
    {
        $storedAthlete = User::factory()->stravaConnected()->create(['strava_athlete_id' => '333']);
        Activity::factory()->for($storedAthlete)->create();
        $this->allowlistAthletes('222', '111');

        DB::enableQueryLog();

        $this->resolve();

        $this->assertCount(2, DB::getQueryLog());
    }

    private function resolve(): ?User
    {
        return app(ResolveDisplayedAthlete::class)();
    }
}
