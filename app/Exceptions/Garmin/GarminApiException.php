<?php

namespace App\Exceptions\Garmin;

use App\Enums\GarminSyncFailure;
use RuntimeException;
use Throwable;

/**
 * A call to the Garmin Connect Personal API failed.
 *
 * The cause is a value from a closed list and every message is fixed text built from
 * the name of the call and an HTTP status, so an exception can be logged, stored as a
 * cause or shown without carrying the API key, the access token, a response body or a
 * request URL. The previous exception is chained for the stack trace only: its own
 * message embeds the URL, so it is never reused.
 */
class GarminApiException extends RuntimeException
{
    public function __construct(
        public readonly GarminSyncFailure $cause,
        public readonly ?int $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The API refused the key or the token it was given.
     */
    public static function keyRejected(string $call, int $status): self
    {
        return new self(GarminSyncFailure::KeyRejected, $status, "Garmin {$call} refused (HTTP {$status}).");
    }

    /**
     * The API could not be reached, timed out or answered with a server-side error.
     */
    public static function unreachable(string $call, ?int $status = null, ?Throwable $previous = null): self
    {
        $message = $status === null
            ? "Garmin {$call} could not be reached."
            : "Garmin {$call} failed (HTTP {$status}).";

        return new self(GarminSyncFailure::Unreachable, $status, $message, $previous);
    }

    /**
     * The API answered with something this client does not understand.
     */
    public static function unexpected(string $call, ?int $status = null): self
    {
        $message = $status === null
            ? "Garmin {$call} answered with an unreadable response."
            : "Garmin {$call} answered with an unexpected response (HTTP {$status}).";

        return new self(GarminSyncFailure::Unexpected, $status, $message);
    }
}
