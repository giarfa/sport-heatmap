<?php

namespace Tests\Feature\Strava;

use App\Data\StravaTokens;
use App\Exceptions\Strava\StravaOAuthException;
use App\Services\Strava\StravaOAuth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The read-only scope guarantee and the refresh-token rotation are the two things
 * in this service that fail silently when they regress, so both get an assertion.
 *
 * Http::preventStrayRequests() means an unfaked endpoint fails the test rather
 * than reaching Strava.
 */
class StravaOAuthTest extends TestCase
{
    private const CLIENT_SECRET = 'test-client-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('strava.client_id', '123456');
        Config::set('strava.client_secret', self::CLIENT_SECRET);
        Config::set('strava.redirect_uri', 'https://sport-heatmap.test/auth/strava/callback');

        Http::preventStrayRequests();
    }

    public function test_the_authorization_url_requests_only_read_access(): void
    {
        $url = $this->oauth()->authorizationUrl('state-value');

        $this->assertStringStartsWith('https://www.strava.com/oauth/authorize?', $url);
        $this->assertStringContainsString('scope=activity%3Aread_all', $url);
        $this->assertStringNotContainsString('write', $url);
    }

    public function test_the_authorization_url_carries_the_flow_parameters(): void
    {
        $url = $this->oauth()->authorizationUrl('state-value');

        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=123456', $url);
        $this->assertStringContainsString(
            'redirect_uri='.urlencode('https://sport-heatmap.test/auth/strava/callback'),
            $url,
        );
        $this->assertStringContainsString('state=state-value', $url);
    }

    public function test_an_authorization_code_is_exchanged_for_a_token_set(): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_at' => 1_788_000_000,
                'athlete' => ['id' => 4821196, 'firstname' => 'Ada', 'lastname' => 'Lovelace'],
            ]),
        ]);

        $tokens = $this->oauth()->exchangeAuthorizationCode('auth-code', 'read,activity:read_all');

        $this->assertSame('fresh-access-token', $tokens->accessToken);
        $this->assertSame('fresh-refresh-token', $tokens->refreshToken);
        $this->assertSame('4821196', $tokens->athleteId);
        $this->assertSame('Ada Lovelace', $tokens->athleteName);
        $this->assertTrue($tokens->hasScope('activity:read_all'));
        $this->assertTrue(
            CarbonImmutable::createFromTimestampUTC(1_788_000_000)->equalTo($tokens->expiresAt),
            'The expiry comes from Strava\'s expires_at unix timestamp.',
        );
    }

    public function test_the_exchange_request_uses_the_authorization_code_grant(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response($this->tokenPayload())]);

        $this->oauth()->exchangeAuthorizationCode('auth-code', 'activity:read_all');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://www.strava.com/oauth/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code';
        });
    }

    public function test_a_failed_exchange_raises_an_exception_that_leaks_nothing(): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'message' => 'Bad Request',
                'access_token' => 'leaked-token-value',
            ], 400),
        ]);

        try {
            $this->oauth()->exchangeAuthorizationCode('auth-code', 'activity:read_all');
            $this->fail('A 400 from the token endpoint must raise StravaOAuthException.');
        } catch (StravaOAuthException $exception) {
            $this->assertStringContainsString('400', $exception->getMessage());
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $exception->getMessage());
            $this->assertStringNotContainsString('leaked-token-value', $exception->getMessage());
        }
    }

    public function test_a_token_response_without_a_refresh_token_is_rejected(): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_at' => 1_788_000_000,
            ]),
        ]);

        $this->expectException(StravaOAuthException::class);
        $this->expectExceptionMessage('refresh_token');

        $this->oauth()->exchangeAuthorizationCode('auth-code', 'activity:read_all');
    }

    public function test_a_refresh_returns_the_rotated_refresh_token(): void
    {
        Http::fake([
            'www.strava.com/oauth/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_at' => 1_788_888_888,
            ]),
        ]);

        $refreshed = $this->oauth()->refreshAccessToken($this->existingTokens());

        $this->assertSame('rotated-access-token', $refreshed->accessToken);
        $this->assertSame('rotated-refresh-token', $refreshed->refreshToken);
        $this->assertNotSame('old-refresh-token', $refreshed->refreshToken);
    }

    public function test_a_refresh_carries_the_athlete_identity_forward(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response($this->tokenPayload())]);

        $refreshed = $this->oauth()->refreshAccessToken($this->existingTokens());

        $this->assertSame('4821196', $refreshed->athleteId);
        $this->assertSame('Ada Lovelace', $refreshed->athleteName);
        $this->assertTrue($refreshed->hasScope('activity:read_all'));
    }

    public function test_the_refresh_request_uses_the_refresh_token_grant(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response($this->tokenPayload())]);

        $this->oauth()->refreshAccessToken($this->existingTokens());

        Http::assertSent(function (Request $request): bool {
            return $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'old-refresh-token';
        });
    }

    public function test_a_rejected_refresh_raises_an_exception(): void
    {
        Http::fake(['www.strava.com/oauth/token' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->expectException(StravaOAuthException::class);
        $this->expectExceptionMessage('401');

        $this->oauth()->refreshAccessToken($this->existingTokens());
    }

    private function oauth(): StravaOAuth
    {
        return new StravaOAuth;
    }

    private function existingTokens(): StravaTokens
    {
        return new StravaTokens(
            accessToken: 'old-access-token',
            refreshToken: 'old-refresh-token',
            expiresAt: CarbonImmutable::now()->subHour(),
            athleteId: '4821196',
            athleteName: 'Ada Lovelace',
            scopes: ['read', 'activity:read_all'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(): array
    {
        return [
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_at' => 1_788_000_000,
            'athlete' => ['id' => 4821196, 'firstname' => 'Ada', 'lastname' => 'Lovelace'],
        ];
    }
}
