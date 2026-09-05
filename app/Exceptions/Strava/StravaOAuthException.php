<?php

namespace App\Exceptions\Strava;

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
        return new self("The Strava token response is missing the \"{$missingKey}\" key.");
    }
}
