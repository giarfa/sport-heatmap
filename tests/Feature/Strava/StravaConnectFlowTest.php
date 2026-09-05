<?php

namespace Tests\Feature\Strava;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The acceptance criteria for US-002 are mostly negative — what must *not* happen
 * when the wrong athlete completes the flow. Every rejection case therefore
 * asserts against empty tables rather than trusting the branch that was taken.
 */
class StravaConnectFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_ATHLETE_ID = '4821196';

    private const STATE = 'a-known-state-value';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('strava.client_id', '123456');
        Config::set('strava.client_secret', 'test-client-secret');
        Config::set('strava.redirect_uri', 'https://sport-heatmap.test/auth/strava/callback');
        Config::set('strava.allowed_athlete_ids', [self::ALLOWED_ATHLETE_ID]);

        Http::preventStrayRequests();
        RateLimiter::clear('strava-oauth');
    }

    public function test_the_redirect_route_sends_the_athlete_to_strava_asking_only_for_read_access(): void
    {
        $response = $this->get(route('strava.redirect'));

        $response->assertRedirectContains('https://www.strava.com/oauth/authorize');
        $response->assertRedirectContains('scope=activity%3Aread_all');
        $response->assertSessionHas('strava.oauth_state');

        $this->assertStringNotContainsString('write', (string) $response->headers->get('Location'));
    }

    public function test_an_allowlisted_athlete_signs_in(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->assertSame(1, User::query()->count());

        $user = User::query()->sole();
        $this->assertSame(self::ALLOWED_ATHLETE_ID, $user->strava_athlete_id);
        $this->assertSame('fresh-access-token', $user->strava_access_token);
        $this->assertSame('fresh-refresh-token', $user->strava_refresh_token);
    }

    /**
     * Session fixation: the id the athlete arrived on must not be the id they
     * leave authenticated on. The comparison has to happen *inside* the request —
     * the test client starts a fresh session per request, so comparing ids across
     * the call would pass even with the regenerate() removed.
     */
    public function test_signing_in_regenerates_the_session_id(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $idAtLogin = null;

        Event::listen(Login::class, function () use (&$idAtLogin): void {
            $idAtLogin = session()->getId();
        });

        $this->withOAuthState()->get($this->callbackUrl())->assertRedirect(route('dashboard'));

        $this->assertNotNull($idAtLogin, 'The athlete was never logged in.');
        $this->assertNotSame($idAtLogin, session()->getId());
    }

    /**
     * The spec's headline criterion: a Strava athlete who is not the owner of this
     * instance gets a rejection screen and leaves no trace whatsoever.
     */
    public function test_a_non_allowlisted_athlete_is_rejected_with_nothing_persisted(): void
    {
        $this->fakeTokenEndpoint('9999999');

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertForbidden();
        $response->assertViewIs('strava.rejected');
        $response->assertSee('9999999', escape: false);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseEmpty('users');
    }

    public function test_a_mismatched_state_is_rejected(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get($this->callbackUrl(state: 'a-different-state'));

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    public function test_a_missing_state_is_rejected(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get('/auth/strava/callback?code=auth-code&scope=read,activity:read_all');

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    public function test_the_state_is_single_use(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $this->withOAuthState()->get($this->callbackUrl())->assertRedirect(route('dashboard'));

        $this->get($this->callbackUrl())->assertForbidden();
    }

    public function test_cancelling_on_strava_returns_to_the_connect_screen(): void
    {
        $response = $this->withOAuthState()->get('/auth/strava/callback?error=access_denied&state='.self::STATE);

        $response->assertRedirect(route('dashboard'));
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');

        Http::assertNothingSent();
    }

    public function test_a_granted_scope_without_read_access_is_rejected_before_any_exchange(): void
    {
        $response = $this->withOAuthState()->get($this->callbackUrl(scope: 'read'));

        $response->assertForbidden();
        $response->assertViewIs('strava.rejected');
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');

        Http::assertNothingSent();
    }

    public function test_a_failing_token_endpoint_surfaces_as_a_service_error_not_a_five_hundred(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response(['message' => 'Bad Request'], 400)]);

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertStatus(503);
        $response->assertViewIs('strava.rejected');
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    public function test_the_callback_is_rate_limited(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->get('/auth/strava/callback?code=auth-code&state=wrong&scope=activity:read_all');
        }

        $this->get('/auth/strava/callback?code=auth-code&state=wrong&scope=activity:read_all')
            ->assertStatus(429);
    }

    public function test_the_redirect_route_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->get(route('strava.redirect'));
        }

        $this->get(route('strava.redirect'))->assertStatus(429);
    }

    public function test_reconnecting_does_not_create_a_second_athlete(): void
    {
        User::factory()->create(['strava_athlete_id' => self::ALLOWED_ATHLETE_ID]);

        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $this->withOAuthState()->get($this->callbackUrl())->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::query()->count());
        $this->assertSame('fresh-access-token', User::query()->sole()->strava_access_token);
    }

    private function withOAuthState(): static
    {
        return $this->withSession(['strava.oauth_state' => self::STATE]);
    }

    private function callbackUrl(string $state = self::STATE, string $scope = 'read,activity:read_all'): string
    {
        return '/auth/strava/callback?'.http_build_query([
            'code' => 'auth-code',
            'state' => $state,
            'scope' => $scope,
        ]);
    }

    private function fakeTokenEndpoint(string $athleteId): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_at' => now()->addHours(6)->getTimestamp(),
                'athlete' => ['id' => (int) $athleteId, 'firstname' => 'Ada', 'lastname' => 'Lovelace'],
            ]),
        ]);
    }
}
