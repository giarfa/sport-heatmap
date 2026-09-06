<?php

namespace App\Exceptions\Strava;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * The Strava activities API returned a non-2xx status the client could not
 * recover from, or the connection to it failed outright.
 *
 * Messages carry the HTTP status and the rate-limit usage header only — never a
 * token, a response body, or a request URL. This is what ends up in
 * sync_runs.error_message, which US-006 renders on the dashboard.
 */
class StravaApiException extends RuntimeException
{
    public static function requestFailed(int $status, ?string $rateLimitUsage): self
    {
        $message = "The Strava activities API returned HTTP {$status}.";

        if ($rateLimitUsage !== null && $rateLimitUsage !== '') {
            $message .= " Rate-limit usage: {$rateLimitUsage}.";
        }

        return new self($message);
    }

    /**
     * DNS, TLS, and timeout failures are the same product outcome as a rejected
     * request — the sync cannot continue — so they enter through the same
     * exception rather than an unhandled 500. The previous exception is chained
     * but its message is not reused: Guzzle embeds the request URL.
     */
    public static function unreachable(ConnectionException $previous): self
    {
        return new self('The Strava activities API could not be reached.', 0, $previous);
    }

    public static function malformedResponse(): self
    {
        return new self('The Strava activities API returned a response that could not be parsed.');
    }
}
