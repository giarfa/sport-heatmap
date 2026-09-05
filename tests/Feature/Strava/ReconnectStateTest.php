<?php

namespace Tests\Feature\Strava;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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

    public function test_a_dead_refresh_token_shows_the_reconnect_screen_rather_than_a_server_error(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response(['message' => 'Unauthorized'], 401)]);

        $user = User::factory()->stravaTokenExpired()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('strava.connect');
        $response->assertSee('Connect with Strava');
        $response->assertSee('expired', escape: false);

        $this->assertGuest();
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

        $user = User::factory()->stravaTokenExpired()->create();

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
}
