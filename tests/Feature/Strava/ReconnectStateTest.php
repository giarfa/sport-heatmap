<?php

namespace Tests\Feature\Strava;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The HTTP surface of the refresh seam: a dead Strava connection has to look like
 * a reconnect prompt, never like a broken application.
 */
class ReconnectStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('strava.client_id', '123456');
        Config::set('strava.client_secret', 'test-client-secret');

        Http::preventStrayRequests();
    }

    public function test_a_dead_refresh_token_signs_the_owner_out_onto_the_public_map_with_the_expiry_banner(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response(['message' => 'Unauthorized'], 401)]);

        $user = $this->allowlistedAthlete(factoryState: 'stravaTokenExpired');
        /*
         * Yesterday, not a fixed offset from the window's end: that Sunday-based offset lands on a
         * future day from Monday to Thursday, which the grid renders as not-yet and never sums.
         */
        Activity::factory()->for($user)->onDate(now()->subDay())->create([
            'distance_meters' => 12_345,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('dashboard'));
        $this->assertGuest();

        $followUp = $this->get(route('dashboard'));

        $followUp->assertOk();
        $followUp->assertViewIs('dashboard');
        $followUp->assertSee('12.3 km');
        $followUp->assertSee('Your Strava connection expired');

        Http::assertSentCount(1);
    }

    public function test_the_expiry_banner_is_shown_once(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response(['message' => 'Unauthorized'], 401)]);

        $user = $this->allowlistedAthlete(factoryState: 'stravaTokenExpired');

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertSee('Your Strava connection expired');

        $this->get(route('dashboard'))->assertOk()->assertDontSee('Your Strava connection expired');
    }

    public function test_a_successful_refresh_renders_the_dashboard_and_stores_the_new_tokens(): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_at' => now()->addHours(6)->getTimestamp(),
            ]),
        ]);

        $user = $this->allowlistedAthlete(factoryState: 'stravaTokenExpired');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard');
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertSame('rotated-access-token', $user->strava_access_token);
        $this->assertSame('rotated-refresh-token', $user->strava_refresh_token);
    }

    public function test_the_middleware_is_a_no_op_for_a_guest(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertViewIs('strava.connect');

        Http::assertNothingSent();
    }

    /**
     * The allowlist is the only access control in this product, and the session
     * it granted outlives an edit to .env by weeks. Removing an athlete has to
     * take effect on their very next request, not whenever they next log out.
     */
    public function test_removing_an_athlete_from_the_allowlist_ends_their_session_immediately(): void
    {
        $user = $this->allowlistedAthlete();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewIs('dashboard');

        Config::set('strava.allowed_athlete_ids', []);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertForbidden();
        $response->assertViewIs('strava.rejected');
        $response->assertSee('This instance is private');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    /**
     * A user row with no athlete id cannot be matched against the allowlist, so
     * the gate has to fail closed rather than read null as "not on the list, but
     * carry on anyway".
     */
    public function test_a_session_without_an_athlete_id_is_turned_away(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertForbidden();
        $this->assertGuest();
    }

    /**
     * An APP_KEY rotation — or a database restored into an environment holding a
     * different one — makes every stored token undecryptable. That is a
     * reconnect, not a 500 on the only screen this product has.
     */
    public function test_undecryptable_tokens_sign_the_owner_out_onto_the_public_map(): void
    {
        $user = $this->allowlistedAthlete();

        DB::table('users')->where('id', $user->getKey())->update([
            'strava_access_token' => 'not-a-ciphertext',
            'strava_refresh_token' => 'not-a-ciphertext',
            'strava_token_expires_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user->fresh())->get(route('dashboard'));

        $response->assertRedirect(route('dashboard'));
        $this->assertGuest();

        /*
         * The displayed athlete's tokens are still undecryptable, but the guest
         * path never reads them — so the public map must render, not 500.
         */
        $followUp = $this->get(route('dashboard'));

        $followUp->assertOk();
        $followUp->assertViewIs('dashboard');
        $followUp->assertSee('Your Strava connection expired');

        Http::assertNothingSent();
    }

    /**
     * A token inside the safety margin has not expired yet, but must still be
     * refreshed before it is handed to a caller.
     */
    public function test_a_token_inside_the_safety_margin_is_refreshed_before_use(): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'margin-access-token',
                'refresh_token' => 'margin-refresh-token',
                'expires_at' => now()->addHours(6)->getTimestamp(),
            ]),
        ]);

        $user = $this->allowlistedAthlete(factoryState: 'stravaTokenExpiringSoon');

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewIs('dashboard');

        $this->assertSame('margin-access-token', $user->fresh()?->strava_access_token);
    }

    /**
     * The refresh holds a lock so two concurrent requests cannot both rotate the
     * refresh token. It must be released on the way out — otherwise the second
     * request blocks and then dead-ends on the reconnect screen.
     */
    public function test_the_refresh_lock_is_released_for_the_next_request(): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_at' => now()->addHours(6)->getTimestamp(),
            ]),
        ]);

        $user = $this->allowlistedAthlete(factoryState: 'stravaTokenExpired');

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewIs('dashboard');
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->assertViewIs('dashboard');

        // The second request found a fresh token and did not call Strava again.
        Http::assertSentCount(1);
    }
}
