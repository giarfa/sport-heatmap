<?php

namespace Tests\Feature\Strava;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\RecordSessionId;
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
     * A recaller outlives SESSION_LIFETIME by about 400 days and signs a lost or
     * shared device back in; sign-in must not mint one (US-019).
     */
    public function test_a_successful_sign_in_sets_no_remember_me_cookie(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
        $this->assertEmpty(User::query()->sole()->getRememberToken());
    }

    /**
     * Session fixation: the id the athlete arrived on must not be the id they
     * leave authenticated on.
     *
     * Both ids are taken *inside* the request — the id as the route was entered,
     * and the id at the moment the Login event fired. Comparing across the call
     * would prove nothing, because the test client starts a fresh session per
     * request; comparing to the id after the controller returned would only prove
     * that some later regenerate() ran, not that the login boundary itself
     * migrated the session.
     */
    public function test_signing_in_regenerates_the_session_id(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        RecordSessionId::$id = null;

        /*
         * The kernel is resolved first on purpose: constructing it syncs its own
         * middleware groups onto the router, which would overwrite a push made
         * before that happened.
         */
        $this->app->make(Kernel::class);
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', RecordSessionId::class);

        $idAtLogin = null;

        Event::listen(Login::class, function () use (&$idAtLogin): void {
            $idAtLogin = session()->getId();
        });

        $this->withOAuthState()->get($this->callbackUrl())->assertRedirect(route('dashboard'));

        $this->assertNotNull(RecordSessionId::$id, 'The request never reached the route.');
        $this->assertNotNull($idAtLogin, 'The athlete was never logged in.');
        $this->assertNotSame(RecordSessionId::$id, $idAtLogin);
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

    /**
     * The request side can only ask for `activity:read_all`, but the athlete can
     * hand-edit the scope on Strava's consent URL. A write-capable token must not
     * be persisted just because it also carries the read scope this app wanted.
     */
    public function test_a_granted_write_scope_is_refused_and_never_exchanged(): void
    {
        $response = $this->withOAuthState()->get($this->callbackUrl(scope: 'read,activity:read_all,activity:write'));

        $response->assertForbidden();
        $response->assertViewIs('strava.rejected');
        $response->assertSee('Only read access is accepted');

        $this->assertGuest();
        $this->assertDatabaseEmpty('users');

        Http::assertNothingSent();
    }

    /**
     * Every callback parameter is attacker-supplied, and `?state[]=x` arrives as
     * an array. Casting one to a string raises a warning Laravel promotes to an
     * exception, so an unguarded cast hands any anonymous caller a 500 — and a
     * stack trace wherever APP_DEBUG is on.
     */
    public function test_array_shaped_callback_parameters_are_rejected_rather_than_crashing(): void
    {
        foreach (['state', 'code', 'scope', 'error'] as $parameter) {
            $response = $this->withOAuthState()
                ->get('/auth/strava/callback?'.http_build_query([$parameter => ['injected']]));

            $response->assertForbidden();
            $this->assertGuest();
        }

        $this->assertDatabaseEmpty('users');
        Http::assertNothingSent();
    }

    public function test_a_callback_without_a_code_is_refused_without_calling_strava(): void
    {
        $response = $this->withOAuthState()
            ->get('/auth/strava/callback?state='.self::STATE.'&scope=read,activity:read_all');

        $response->assertStatus(400);
        $response->assertViewIs('strava.rejected');
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');

        Http::assertNothingSent();
    }

    /**
     * A DNS, TLS, or timeout failure is a ConnectionException, not a response —
     * it has to land on the same rejection screen as a refused exchange rather
     * than escaping the catch block as a 500.
     */
    public function test_an_unreachable_token_endpoint_surfaces_as_a_service_error_not_a_five_hundred(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertStatus(503);
        $response->assertViewIs('strava.rejected');
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    /**
     * Cancelling is the one path that used to return before the state was pulled,
     * leaving a live single-use nonce in the session for a later replay.
     */
    public function test_cancelling_consumes_the_state_too(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $this->withOAuthState()
            ->get('/auth/strava/callback?error=access_denied&state='.self::STATE)
            ->assertRedirect(route('dashboard'));

        $this->get($this->callbackUrl())->assertForbidden();
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
