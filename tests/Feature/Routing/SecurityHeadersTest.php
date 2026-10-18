<?php

namespace Tests\Feature\Routing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * No page may be framed by another site (US-018): the Garmin import review's
 * Overwrite controls would otherwise be open to UI redress, which a CSRF token
 * does not stop.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, int}>
     */
    public static function webResponses(): array
    {
        return [
            'dashboard' => ['/', 200],
            'garmin import form' => ['/import/garmin', 200],
            'unknown path' => ['/no-such-page', 404],
        ];
    }

    #[DataProvider('webResponses')]
    public function test_every_web_response_carries_the_four_security_headers(string $uri, int $status): void
    {
        $response = $this->get($uri);

        $response->assertStatus($status);
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_hsts_is_not_sent_over_http(): void
    {
        $response = $this->get('http://sport-heatmap.test/');

        $response->assertOk();
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_over_https_without_subdomains_or_preload(): void
    {
        $response = $this->get('https://sport-heatmap.test/');

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_a_header_the_route_already_set_is_left_alone(): void
    {
        Route::get('/framed-on-purpose', fn () => response('ok')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "frame-ancestors 'self'"));

        $response = $this->get('/framed-on-purpose');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
