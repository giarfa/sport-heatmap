<?php

namespace Tests\Feature\Strava;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The OAuth callback attaches Strava tokens to the signed-in owner and signs
 * nobody in (US-020). The acceptance criteria are mostly negative — what must
 * *not* happen when the wrong athlete completes the flow — so every rejection
 * case asserts that the owner's Strava columns are still empty rather than
 * trusting the branch that was taken.
 */
class StravaConnectFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_ATHLETE_ID = '4821196';

    private const STATE = 'a-known-state-value';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->signInOwner();

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

    public function test_an_allowlisted_athlete_is_attached_to_the_signed_in_owner(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->owner);

        $this->assertSame(1, User::query()->count());

        $owner = $this->owner->fresh();
        $this->assertSame(self::ALLOWED_ATHLETE_ID, $owner->strava_athlete_id);
        $this->assertSame('fresh-access-token', $owner->strava_access_token);
        $this->assertSame('fresh-refresh-token', $owner->strava_refresh_token);
        $this->assertSame('giarfa', $owner->username);
    }

    /**
     * The callback used to be the sign-in. It is behind the session gate now, so a
     * signed-out request never reaches Strava or writes anything.
     */
    public function test_a_signed_out_callback_goes_to_the_sign_in_page_and_writes_nothing(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);
        auth()->logout();

        $this->withOAuthState()->get($this->callbackUrl())->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNothingStored();
        Http::assertNothingSent();
    }

    public function test_the_callback_sets_no_remember_me_cookie(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertRedirect(route('dashboard'));
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
    }

    /**
     * A Strava id another row already holds would hand the owner somebody else's
     * identity (and break the unique index); nothing is written.
     */
    public function test_an_athlete_id_held_by_another_row_is_rejected_with_nothing_written(): void
    {
        User::factory()->create(['strava_athlete_id' => self::ALLOWED_ATHLETE_ID]);
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertForbidden();
        $response->assertViewIs('strava.rejected');
        $this->assertSame(2, User::query()->count());
        $this->assertNull($this->owner->fresh()->strava_athlete_id);
        $this->assertNull($this->owner->fresh()->strava_access_token);
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

        $this->assertAuthenticatedAs($this->owner);
        $this->assertNothingStored();
    }

    public function test_a_mismatched_state_is_rejected(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get($this->callbackUrl(state: 'a-different-state'));

        $response->assertForbidden();
        $this->assertNothingStored();
    }

    public function test_a_missing_state_is_rejected(): void
    {
        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $response = $this->withOAuthState()->get('/auth/strava/callback?code=auth-code&scope=read,activity:read_all');

        $response->assertForbidden();
        $this->assertNothingStored();
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
        $this->assertNothingStored();

        Http::assertNothingSent();
    }

    public function test_a_granted_scope_without_read_access_is_rejected_before_any_exchange(): void
    {
        $response = $this->withOAuthState()->get($this->callbackUrl(scope: 'read'));

        $response->assertForbidden();
        $response->assertViewIs('strava.rejected');
        $this->assertNothingStored();

        Http::assertNothingSent();
    }

    public function test_a_failing_token_endpoint_surfaces_as_a_service_error_not_a_five_hundred(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response(['message' => 'Bad Request'], 400)]);

        $response = $this->withOAuthState()->get($this->callbackUrl());

        $response->assertStatus(503);
        $response->assertViewIs('strava.rejected');
        $this->assertNothingStored();
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

        $this->assertNothingStored();

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
        }

        $this->assertNothingStored();
        Http::assertNothingSent();
    }

    public function test_a_callback_without_a_code_is_refused_without_calling_strava(): void
    {
        $response = $this->withOAuthState()
            ->get('/auth/strava/callback?state='.self::STATE.'&scope=read,activity:read_all');

        $response->assertStatus(400);
        $response->assertViewIs('strava.rejected');
        $this->assertNothingStored();

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
        $this->assertNothingStored();
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
        $this->assertNothingStored();
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

    public function test_reconnecting_replaces_the_tokens_without_adding_a_user(): void
    {
        $this->owner->forceFill([
            'strava_athlete_id' => self::ALLOWED_ATHLETE_ID,
            'strava_access_token' => 'stale-access-token',
        ])->save();

        $this->fakeTokenEndpoint(self::ALLOWED_ATHLETE_ID);

        $this->withOAuthState()->get($this->callbackUrl())->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::query()->count());
        $this->assertSame('fresh-access-token', User::query()->sole()->strava_access_token);
    }

    /**
     * Nothing was attached: the owner is still the only user and holds no Strava
     * identity or token.
     */
    private function assertNothingStored(): void
    {
        $this->assertSame(1, User::query()->count());
        $this->assertNull(User::query()->sole()->strava_athlete_id);
        $this->assertNull(User::query()->sole()->strava_access_token);
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
