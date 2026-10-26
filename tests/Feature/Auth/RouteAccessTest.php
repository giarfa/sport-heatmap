<?php

namespace Tests\Feature\Auth;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The gate is proven from the route table, not from a hand-written list, so a
 * route added by a later spec cannot open itself by accident (FR-021).
 */
class RouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes the gate deliberately leaves open or that the deployed app does not
     * carry: the sign-in page, the health route, and the development-only
     * Larapilot dashboard and mockup viewer, Boost, Ignition and storage routes
     * (matched by URI or route name).
     */
    private const OPEN_OR_DEV_ROUTES = '#^(login|up|storage/.*|_boost/.*|_ignition/.*|larapilot(/.*)?)$#';

    private const DEV_ROUTE_NAMES = '#^(larapilot|boost|ignition|storage)\.#';

    /**
     * The routes only the signed-in owner with no password yet may reach.
     *
     * @var array<int, string>
     */
    private const FIRST_PASSWORD_ROUTES = ['password.change', 'password.update', 'logout'];

    public function test_the_route_table_is_not_empty(): void
    {
        $this->assertNotEmpty($this->gatedRoutes(), 'The matrix found no route to prove.');
    }

    public function test_every_route_but_login_and_the_health_route_sends_a_signed_out_request_to_the_sign_in_page(): void
    {
        $before = [Activity::count(), User::count()];

        foreach ($this->gatedRoutes() as [$method, $uri]) {
            $response = $this->call($method, $uri);

            $response->assertRedirect(route('login'));
            $response->assertHeaderMissing('WWW-Authenticate');
            $this->assertGuest();
        }

        $this->assertSame($before, [Activity::count(), User::count()], 'A signed-out request wrote a row.');
    }

    public function test_the_health_route_and_the_sign_in_page_stay_open_without_a_basic_auth_challenge(): void
    {
        $this->get('/up')->assertOk()->assertHeaderMissing('WWW-Authenticate');
        $this->get('/login')->assertOk()->assertHeaderMissing('WWW-Authenticate');
    }

    public function test_an_owner_with_no_password_is_sent_to_the_first_password_page_from_every_other_route(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $checked = 0;

        foreach ($this->gatedRoutes() as [$method, $uri, $name]) {
            if (in_array($name, self::FIRST_PASSWORD_ROUTES, true)) {
                continue;
            }

            $response = $this->call($method, $uri);

            $response->assertRedirect(route('password.change'));
            $response->assertHeaderMissing('WWW-Authenticate');
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_the_first_password_routes_are_the_only_ones_reachable_with_no_password(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get(route('password.change'))->assertOk();
        $this->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_the_sign_in_and_first_password_pages_keep_noindex_and_the_security_headers(): void
    {
        $this->assertSecureNoindexPage($this->get('/login'));

        $this->actingAs(User::factory()->owner()->create());

        $this->assertSecureNoindexPage($this->get('/password/change'));
    }

    public function test_a_signed_in_owner_with_a_password_reaches_the_protected_pages(): void
    {
        $this->signInOwner();

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('garmin.import.create'))->assertOk();
        $this->get(route('password.change'))->assertRedirect(route('dashboard'));
    }

    public function test_failed_attempts_do_not_lock_the_owner_out_once_the_minute_has_passed(): void
    {
        User::factory()->ownerWithPassword('correct horse battery')->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.store'), ['username' => 'giarfa', 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['username' => 'giarfa', 'password' => 'correct horse battery'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->travel(61)->seconds();

        $this->post(route('login.store'), ['username' => 'giarfa', 'password' => 'correct horse battery'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    /**
     * Every method of every registered route outside the open and development-only
     * ones, with route parameters filled in.
     *
     * @return array<int, array{string, string, string|null}>
     */
    private function gatedRoutes(): array
    {
        $gated = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (preg_match(self::OPEN_OR_DEV_ROUTES, $route->uri()) === 1
                || preg_match(self::DEV_ROUTE_NAMES, (string) $route->getName()) === 1) {
                continue;
            }

            $uri = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', 'x', $route->uri()), '/');

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $gated[] = [$method, $uri, $route->getName()];
            }
        }

        return $gated;
    }

    private function assertSecureNoindexPage(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }
}
