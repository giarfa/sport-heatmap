<?php

namespace App\Support\Garmin;

use App\Exceptions\Garmin\GarminApiException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reads the owner's activities from the Garmin Connect Personal API.
 *
 * Hand-rolled on Laravel's HTTP client: Garmin's only official client is Python and no
 * maintained PHP package exists. The key is exchanged for a 24 hour access token, the
 * token is reused until an hour before it expires, and every failure becomes a
 * GarminApiException with a cause and no secret in it.
 */
final class GarminPersonalApiClient
{
    private const TOKEN_CACHE_KEY = 'garmin:personal-api-token';

    private const TOKEN_CALL = 'token exchange';

    private const ACTIVITIES_CALL = 'activity search';

    /**
     * An hour of margin: a token that expires mid-sync would turn a long history read
     * into a 401 on page N, so a token in its last hour is replaced rather than reused.
     */
    private const EXPIRY_MARGIN_SECONDS = 3_600;

    /**
     * Whether a key is configured. With none, nothing in the sync may call out.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * One page of the owner's activities, newest first, as Garmin returns them.
     *
     * An item that is not an object is kept as an empty array so the page keeps its
     * length — the caller stops paging on a short page — and the DTO counts it invalid.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GarminApiException
     */
    public function activities(int $start, int $limit, ?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): array
    {
        $query = array_filter([
            'start' => $start,
            'limit' => $limit,
            'startDate' => $startDate?->toDateString(),
            'endDate' => $endDate?->toDateString(),
        ], static fn (int|string|null $value): bool => $value !== null);

        $response = $this->searchActivities($query);

        if ($response->status() === 401) {
            /*
             * A 401 on a data call usually means the cached token was revoked or replaced
             * before its `exp`. The token is dropped and exchanged once more, and the call
             * is retried once: a second refusal is the key being refused, not a stale
             * token, and looping on it would hammer the API.
             */
            $this->tokenStore()->forget(self::TOKEN_CACHE_KEY);
            $response = $this->searchActivities($query);
        }

        $this->guardStatus($response, self::ACTIVITIES_CALL);

        $page = $response->json();

        if (! is_array($page) || ! array_is_list($page)) {
            throw GarminApiException::unexpected(self::ACTIVITIES_CALL, $response->status());
        }

        return array_map(
            static fn (mixed $item): array => is_array($item) ? $item : [],
            $page,
        );
    }

    /**
     * @param  array<string, int|string>  $query
     *
     * @throws GarminApiException
     */
    private function searchActivities(array $query): Response
    {
        $token = $this->token();

        try {
            return $this->request()
                ->withToken($token)
                ->acceptJson()
                ->get(config('services.garmin.activities_path'), $query);
        } catch (ConnectionException $exception) {
            throw GarminApiException::unreachable(self::ACTIVITIES_CALL, previous: $exception);
        }
    }

    /**
     * The access token: the cached one, or a fresh exchange of the key.
     *
     * @throws GarminApiException
     */
    private function token(): string
    {
        $cached = $this->tokenStore()->get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->exchangeKey();
        $secondsLeft = $this->expiry($token) - now()->getTimestamp() - self::EXPIRY_MARGIN_SECONDS;

        /*
         * A token already inside its margin is still good for this call, but caching it
         * would make the next call exchange again anyway. It is used once and not kept.
         */
        if ($secondsLeft > 0) {
            $this->tokenStore()->put(self::TOKEN_CACHE_KEY, $token, $secondsLeft);
        }

        return $token;
    }

    /**
     * @throws GarminApiException
     */
    private function exchangeKey(): string
    {
        $key = $this->apiKey();

        if ($key === '') {
            throw GarminApiException::unexpected(self::TOKEN_CALL);
        }

        try {
            $response = $this->request()
                ->withHeaders(['Authorization' => "ApiKey {$key}"])
                ->post(config('services.garmin.token_path'));
        } catch (ConnectionException $exception) {
            throw GarminApiException::unreachable(self::TOKEN_CALL, previous: $exception);
        }

        $this->guardStatus($response, self::TOKEN_CALL);

        /*
         * The body is the bare JWT, not JSON. Surrounding whitespace and quotes are
         * stripped in case a proxy wraps it, and an empty body is a failure rather than
         * an empty token that the next call would send as `Bearer `.
         */
        $token = trim($response->body(), " \t\n\r\0\x0B\"'");

        if ($token === '') {
            throw GarminApiException::unexpected(self::TOKEN_CALL, $response->status());
        }

        return $token;
    }

    /**
     * The `exp` claim of the token, read from its payload segment.
     *
     * The signature is not verified: this is the client, not the verifier, and the API
     * checks the token on every call. A token with no readable `exp` is a failure — a
     * guessed lifetime would either expire mid-sync or never be renewed.
     *
     * @throws GarminApiException
     */
    private function expiry(string $token): int
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw GarminApiException::unexpected(self::TOKEN_CALL);
        }

        $payload = base64_decode(strtr($segments[1], '-_', '+/'), true);
        $claims = $payload === false ? null : json_decode($payload, true);

        if (! is_array($claims) || ! isset($claims['exp']) || ! is_int($claims['exp'])) {
            throw GarminApiException::unexpected(self::TOKEN_CALL);
        }

        return $claims['exp'];
    }

    /**
     * Turn a non-success status into the exception with its cause.
     *
     * @throws GarminApiException
     */
    private function guardStatus(Response $response, string $call): void
    {
        $status = $response->status();

        if ($response->successful()) {
            return;
        }

        throw match (true) {
            $status === 401, $status === 403 => GarminApiException::keyRejected($call, $status),
            $status === 429, $status >= 500 => GarminApiException::unreachable($call, $status),
            default => GarminApiException::unexpected($call, $status),
        };
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.garmin.base_url'))
            ->connectTimeout(config('services.garmin.connect_timeout'))
            ->timeout(config('services.garmin.timeout'));
    }

    /**
     * Always the configured store, never the default one: the default is the database
     * cache, which would put the token in SQLite.
     */
    private function tokenStore(): Repository
    {
        return Cache::store(config('services.garmin.token_cache_store'));
    }

    private function apiKey(): string
    {
        $key = config('services.garmin.api_key');

        return is_string($key) ? trim($key) : '';
    }
}
