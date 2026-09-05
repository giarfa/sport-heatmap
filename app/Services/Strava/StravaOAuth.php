<?php

namespace App\Services\Strava;

use App\Data\StravaTokens;
use App\Exceptions\Strava\StravaOAuthException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only class that speaks to Strava's OAuth endpoints.
 *
 * Hand-rolled on the HTTP client rather than Socialite: Strava is
 * authorization-code OAuth2 only, and socialite's league/oauth1-client
 * dependency would force this project's Guzzle 8 down to Guzzle 7.
 */
class StravaOAuth
{
    /**
     * Build the URL the browser is sent to in order to authorize this app.
     *
     * The scope comes from config as a literal, never from the caller, so no code
     * path can widen it.
     */
    public function authorizationUrl(string $state): string
    {
        return config('strava.authorize_url').'?'.http_build_query([
            'client_id' => config('strava.client_id'),
            'redirect_uri' => config('strava.redirect_uri'),
            'response_type' => 'code',
            'approval_prompt' => 'auto',
            'scope' => config('strava.scope'),
            'state' => $state,
        ]);
    }

    /**
     * Exchange an authorization code for a token set.
     *
     * @throws StravaOAuthException
     */
    public function exchangeAuthorizationCode(string $code, string $grantedScope): StravaTokens
    {
        $payload = $this->postToTokenEndpoint([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        return StravaTokens::fromExchangeResponse($payload, $grantedScope);
    }

    /**
     * Trade a refresh token for a fresh access token.
     *
     * Strava rotates the refresh token on every refresh, so the returned set
     * carries a new refresh token too — keeping the old one breaks the next call.
     *
     * @throws StravaOAuthException
     */
    public function refreshAccessToken(StravaTokens $current): StravaTokens
    {
        $payload = $this->postToTokenEndpoint([
            'grant_type' => 'refresh_token',
            'refresh_token' => $current->refreshToken,
        ]);

        return StravaTokens::fromRefreshResponse($payload, $current);
    }

    /**
     * @param  array<string, string>  $grant
     * @return array<string, mixed>
     *
     * @throws StravaOAuthException
     */
    private function postToTokenEndpoint(array $grant): array
    {
        /** @var Response $response */
        $response = Http::asForm()->post(config('strava.token_url'), [
            'client_id' => config('strava.client_id'),
            'client_secret' => config('strava.client_secret'),
            ...$grant,
        ]);

        if ($response->failed()) {
            throw StravaOAuthException::tokenEndpointFailed($response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw StravaOAuthException::malformedTokenResponse('access_token');
        }

        foreach (['access_token', 'refresh_token', 'expires_at'] as $key) {
            if (! isset($payload[$key]) || ! is_scalar($payload[$key])) {
                throw StravaOAuthException::malformedTokenResponse($key);
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
