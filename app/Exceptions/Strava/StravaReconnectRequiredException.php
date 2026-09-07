<?php

namespace App\Exceptions\Strava;

use RuntimeException;

/**
 * The stored Strava credentials are no longer usable — typically because the
 * athlete revoked this application on Strava's side, or because APP_KEY changed
 * underneath the encrypted columns.
 *
 * An expired connection is an expected state of this product, not a server
 * fault. This exception carries no render() of its own: the caller decides what
 * that means. On the web that is EnsureFreshStravaToken, which ends the session
 * and renders the connect screen in its reconnect state; the scheduled sync in a
 * later spec will record it against the sync run instead.
 */
class StravaReconnectRequiredException extends RuntimeException {}
