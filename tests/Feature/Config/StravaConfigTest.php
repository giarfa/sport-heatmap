<?php

namespace Tests\Feature\Config;

use Tests\TestCase;

/**
 * The OAuth scope is the one setting that must never be loosened by a deployment.
 * Asserting it here makes "read-only" a property of the repository rather than of
 * whatever .env happens to be on the server.
 */
class StravaConfigTest extends TestCase
{
    public function test_the_requested_scope_is_exactly_activity_read_all(): void
    {
        $this->assertSame('activity:read_all', config('strava.scope'));
    }

    public function test_the_requested_scope_never_asks_for_write_access(): void
    {
        $this->assertStringNotContainsString('write', (string) config('strava.scope'));
    }

    public function test_the_oauth_endpoints_are_strava_over_https(): void
    {
        foreach (['strava.authorize_url', 'strava.token_url'] as $key) {
            $url = (string) config($key);

            $this->assertStringStartsWith('https://', $url, "{$key} must be HTTPS.");
            $this->assertSame('www.strava.com', parse_url($url, PHP_URL_HOST), "{$key} must point at Strava.");
        }
    }

    public function test_the_refresh_margin_defaults_to_five_minutes(): void
    {
        $this->assertSame(300, config('strava.token_refresh_margin_seconds'));
    }

    /**
     * Environment values arrive as strings. Larastan trusts the array shape
     * docblock on config/strava.php, so the cast has to be real.
     */
    public function test_a_string_environment_value_is_cast_to_an_integer(): void
    {
        $_SERVER['STRAVA_TOKEN_REFRESH_MARGIN'] = '600';

        try {
            $config = require config_path('strava.php');
        } finally {
            unset($_SERVER['STRAVA_TOKEN_REFRESH_MARGIN']);
        }

        $this->assertSame(600, $config['token_refresh_margin_seconds']);
    }
}
