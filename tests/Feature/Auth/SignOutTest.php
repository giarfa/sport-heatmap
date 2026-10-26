<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_signing_out_ends_the_session_and_returns_to_the_sign_in_page(): void
    {
        $owner = $this->signInOwner();

        $response = $this->actingAs($owner)->withSession(['_token' => 'before'])->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_out_flushes_the_session_and_issues_a_fresh_csrf_token(): void
    {
        $owner = $this->signInOwner();

        $this->actingAs($owner)
            ->withSession(['_token' => 'before', 'left-behind' => 'owner data'])
            ->post(route('logout'));

        $this->assertNull(session('left-behind'));
        $this->assertNotEmpty(session()->token());
        $this->assertNotSame('before', session()->token());
    }

    public function test_there_is_no_get_route_to_sign_out(): void
    {
        $owner = $this->signInOwner();

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
        $owner = $this->signInOwner();

        $this->actingAs($owner)->post(route('logout'))->assertStatus(419);

        $this->assertAuthenticatedAs($owner);
    }

    public function test_signing_out_revokes_a_remember_token_issued_before_the_fix(): void
    {
        $owner = $this->signInOwner();

        $this->actingAs($owner)->post(route('logout'));

        $this->assertNotSame('a-recaller-from-2-0-1', $owner->refresh()->getRememberToken());
    }

    public function test_the_dashboard_offers_sign_out_as_a_csrf_form(): void
    {
        $owner = $this->signInOwner();

        $signedIn = $this->actingAs($owner)->get(route('dashboard'));

        $signedIn->assertOk();
        $signedIn->assertSee('action="'.route('logout').'"', escape: false);
        $signedIn->assertSee('method="POST"', escape: false);
        $signedIn->assertSee('name="_token"', escape: false);
        $signedIn->assertSeeInOrder(['<button type="submit"', 'Sign out</button>'], escape: false);
    }
}
