<?php

namespace Tests\Feature\Routing;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Public signup is FR-016 (Won't): this is a private, single-owner product.
 *
 * These assertions are what keeps a later starter-kit install from quietly
 * reopening registration.
 */
class NoPublicSignupTest extends TestCase
{
    public function test_there_is_no_named_register_route(): void
    {
        $this->assertFalse(Route::has('register'));
    }

    /**
     * The dev-only Larapilot dashboard is excluded: its `security/register.md`
     * is a security findings register, not a signup page.
     */
    public function test_no_registered_route_uri_mentions_registration(): void
    {
        $registrationRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RoutingRoute $route): string => $route->uri())
            ->reject(fn (string $uri): bool => str_starts_with($uri, 'larapilot/'))
            ->filter(fn (string $uri): bool => str_contains($uri, 'register'))
            ->values();

        $this->assertSame(
            [],
            $registrationRoutes->all(),
            'A registration route exists: public signup is out of scope (FR-016).',
        );
    }

    /**
     * US-020: one account, so no route creates, lists, or edits users. The sign-in
     * and the first password change are the only routes that touch the account.
     */
    public function test_no_route_creates_or_lists_users(): void
    {
        $userRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RoutingRoute $route): string => $route->uri())
            ->reject(fn (string $uri): bool => str_starts_with($uri, 'larapilot/'))
            ->filter(fn (string $uri): bool => str_contains($uri, 'user'))
            ->values();

        $this->assertSame([], $userRoutes->all(), 'A route manages users: this instance has one account.');
    }

    public function test_the_register_url_is_not_reachable(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
