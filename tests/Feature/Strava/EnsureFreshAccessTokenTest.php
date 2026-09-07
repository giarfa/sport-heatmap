<?php

namespace Tests\Feature\Strava;

use App\Actions\Strava\EnsureFreshAccessToken;
use App\Exceptions\Strava\StravaReconnectRequiredException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnsureFreshAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('strava.client_id', '123456');
        Config::set('strava.client_secret', 'test-client-secret');

        Http::preventStrayRequests();
    }

    public function test_a_comfortably_valid_token_costs_no_http_request(): void
    {
        $user = User::factory()->stravaConnected()->create();

        $this->assertSame($user->strava_access_token, $this->ensureFresh($user));

        Http::assertNothingSent();
    }

    public function test_an_expired_token_is_refreshed_and_the_rotated_pair_is_stored(): void
    {
        $this->fakeRefreshEndpoint();

        $user = User::factory()->stravaTokenExpired()->create();
        $previousRefreshToken = $user->strava_refresh_token;

        $this->assertSame('rotated-access-token', $this->ensureFresh($user));

        $user->refresh();
        $this->assertSame('rotated-access-token', $user->strava_access_token);
        $this->assertSame('rotated-refresh-token', $user->strava_refresh_token);
        $this->assertNotSame($previousRefreshToken, $user->strava_refresh_token);
        $this->assertTrue($user->strava_token_expires_at->isFuture());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token');
    }

    /**
     * The margin is the whole point: a token that has not technically expired can
     * still die mid-request.
     */
    public function test_a_token_inside_the_safety_margin_is_refreshed_too(): void
    {
        $this->fakeRefreshEndpoint();

        $user = User::factory()->stravaTokenExpiringSoon()->create();

        $this->assertTrue($user->strava_token_expires_at->isFuture());
        $this->assertSame('rotated-access-token', $this->ensureFresh($user));

        Http::assertSentCount(1);
    }

    public function test_a_missing_expiry_is_treated_as_expired(): void
    {
        $this->fakeRefreshEndpoint();

        $user = User::factory()->stravaConnected()->create(['strava_token_expires_at' => null]);

        $this->assertSame('rotated-access-token', $this->ensureFresh($user));

        Http::assertSentCount(1);
    }

    public function test_an_athlete_without_a_refresh_token_must_reconnect(): void
    {
        $user = User::factory()->create(['strava_athlete_id' => '4821196']);

        $this->expectException(StravaReconnectRequiredException::class);

        try {
            $this->ensureFresh($user);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_rejected_refresh_leaves_the_stored_tokens_untouched(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response(['message' => 'Unauthorized'], 401)]);

        $user = User::factory()->stravaTokenExpired()->create();
        $originalAccessToken = $user->strava_access_token;
        $originalRefreshToken = $user->strava_refresh_token;

        try {
            $this->ensureFresh($user);
            $this->fail('A rejected refresh must raise StravaReconnectRequiredException.');
        } catch (StravaReconnectRequiredException) {
            $user->refresh();

            $this->assertSame($originalAccessToken, $user->strava_access_token);
            $this->assertSame($originalRefreshToken, $user->strava_refresh_token);
        }
    }

    private function ensureFresh(User $user): string
    {
        return app(EnsureFreshAccessToken::class)($user);
    }

    private function fakeRefreshEndpoint(): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_at' => now()->addHours(6)->getTimestamp(),
            ]),
        ]);
    }
}
