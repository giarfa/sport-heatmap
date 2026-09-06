<?php

namespace App\Exceptions\Strava;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * Strava's token endpoint returned a non-2xx status or a body missing a key the
 * flow depends on.
 *
 * The messages deliberately carry the HTTP status and the missing key only. The
 * response body of a token call holds live credentials, so it must never reach
 * an exception message, a log line, or a stack trace.
 */
class StravaOAuthException extends RuntimeException
{
    public static function tokenEndpointFailed(int $status): self
    {
        return new self("The Strava token endpoint returned HTTP {$status}.");
    }

    public static function malformedTokenResponse(string $missingKey): self
    {
        return new self("The Strava token response is missing a usable \"{$missingKey}\" value.");
    }

    /**
     * DNS, TLS, and timeout failures are the same product outcome as a rejected
     * token call — the flow cannot continue — so they enter through the same
     * exception rather than escaping as a 500. The previous exception is chained
     * but its message is not reused: Guzzle embeds the request URL.
     */
    public static function tokenEndpointUnreachable(ConnectionException $previous): self
    {
        return new self('The Strava token endpoint could not be reached.', 0, $previous);
    }
}
