<?php

namespace Tests\Feature\Auth;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signing out: a CSRF-protected POST that ends the session and returns to the sign-in page.
 */
class SignOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Config::set('strava.retry_backoff_ms', [0, 0, 0]);
    }

    public function test_signing_out_ends_the_session_and_returns_to_the_sign_in_page(): void
    {
        $owner = $this->allowlistedAthlete();

        $response = $this->actingAs($owner)->withSession(['_token' => 'before'])->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_out_flushes_the_session_and_issues_a_fresh_csrf_token(): void
    {
        $owner = $this->allowlistedAthlete();

        $this->actingAs($owner)
            ->withSession(['_token' => 'before', 'left-behind' => 'owner data'])
            ->post(route('logout'));

        $this->assertNull(session('left-behind'));
        $this->assertNotEmpty(session()->token());
        $this->assertNotSame('before', session()->token());
    }

    public function test_there_is_no_get_route_to_sign_out(): void
    {
        $owner = $this->allowlistedAthlete();

        $this->actingAs($owner)->get('/logout')->assertMethodNotAllowed();

        $this->assertAuthenticatedAs($owner);
    }

    public function test_a_post_without_a_csrf_token_is_refused_and_keeps_the_owner_signed_in(): void
    {
        /*
         * The framework skips CSRF while running tests; swap in a copy that
         * enforces it, and send no Sec-Fetch-Site header so the origin
         * shortcut cannot let the request through either.
         */
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $owner = $this->allowlistedAthlete();

        $this->actingAs($owner)->post(route('logout'))->assertStatus(419);

        $this->assertAuthenticatedAs($owner);
    }

    public function test_signing_out_keeps_the_strava_tokens_and_the_nightly_sync_still_runs(): void
    {
        $owner = $this->allowlistedAthlete();
        $accessToken = $owner->strava_access_token;
        $refreshToken = $owner->strava_refresh_token;
        $expiresAt = $owner->strava_token_expires_at;

        $this->actingAs($owner)->post(route('logout'));
        $this->assertGuest();

        $owner->refresh();
        $this->assertSame($accessToken, $owner->strava_access_token);
        $this->assertSame($refreshToken, $owner->strava_refresh_token);
        $this->assertTrue($expiresAt->equalTo($owner->strava_token_expires_at));

        Http::fake(['www.strava.com/api/v3/athlete/activities*' => Http::response([])]);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(SyncRunStatus::Succeeded, SyncRun::sole()->status);
    }

    public function test_signing_out_revokes_a_remember_token_issued_before_the_fix(): void
    {
        $owner = $this->allowlistedAthlete(['remember_token' => 'a-recaller-from-2-0-1']);

        $this->actingAs($owner)->post(route('logout'));

        $this->assertNotSame('a-recaller-from-2-0-1', $owner->refresh()->getRememberToken());
    }

    public function test_the_dashboard_offers_sign_out_as_a_csrf_form(): void
    {
        $owner = $this->allowlistedAthlete();

        $signedIn = $this->actingAs($owner)->get(route('dashboard'));

        $signedIn->assertOk();
        $signedIn->assertSee('action="'.route('logout').'"', escape: false);
        $signedIn->assertSee('method="POST"', escape: false);
        $signedIn->assertSee('name="_token"', escape: false);
        $signedIn->assertSeeInOrder(['<button type="submit"', 'Sign out</button>'], escape: false);
    }
}
