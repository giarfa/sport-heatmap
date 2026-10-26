<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SignInTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    public function test_the_form_renders_in_the_layout_with_labelled_fields_and_no_basic_auth_challenge(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
        $response->assertSee('name="_token"', escape: false);
        $response->assertSee('<label for="username"', escape: false);
        $response->assertSee('<label for="password"', escape: false);
        $response->assertSee('autocomplete="username"', escape: false);
        $response->assertSee('autocomplete="current-password"', escape: false);
        $response->assertSee('Skip to content');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeaderMissing('WWW-Authenticate');
    }

    public function test_the_correct_password_signs_the_owner_in_and_lands_on_the_dashboard(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        $this->post(route('login.store'), ['username' => 'giarfa', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_a_wrong_password_and_a_wrong_username_give_the_same_single_error(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        $wrongPassword = $this->from(route('login'))
            ->post(route('login.store'), ['username' => 'giarfa', 'password' => 'not the password']);
        $wrongUsername = $this->from(route('login'))
            ->post(route('login.store'), ['username' => 'someone', 'password' => self::PASSWORD]);

        foreach ([$wrongPassword, $wrongUsername] as $response) {
            $response->assertRedirect(route('login'));
            $response->assertSessionHasErrors(['username' => 'These credentials do not match our records.']);
        }

        $this->assertGuest();
    }

    public function test_a_failed_sign_in_keeps_the_username_and_never_flashes_the_password(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        $response = $this->post(route('login.store'), ['username' => 'giarfa', 'password' => 'not the password']);

        $response->assertSessionHasInput('username', 'giarfa');
        $response->assertSessionMissing('_old_input.password');

        $this->followRedirects($response)
            ->assertSee('value="giarfa"', escape: false)
            ->assertDontSee('not the password');
    }

    public function test_the_error_is_announced_and_linked_to_the_field(): void
    {
        $response = $this->followingRedirects()
            ->post(route('login.store'), ['username' => 'someone', 'password' => 'x']);

        $response->assertSee('role="alert"', escape: false);
        $response->assertSee('aria-invalid="true"', escape: false);
        $response->assertSee('aria-describedby="username-error"', escape: false);
        $response->assertSee('These credentials do not match our records.');
    }

    public function test_the_sixth_attempt_inside_a_minute_is_refused_with_the_seconds_and_no_credential_check(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.store'), ['username' => 'giarfa', 'password' => 'wrong']);
        }

        $hasher = Hash::spy();

        $response = $this->post(route('login.store'), ['username' => 'giarfa', 'password' => self::PASSWORD]);

        $response->assertSessionHasErrors('username');
        $this->assertMatchesRegularExpression(
            '/^Too many sign-in attempts\. Try again in \d+ seconds\.$/',
            session('errors')->first('username'),
        );
        $hasher->shouldNotHaveReceived('check');
        $this->assertGuest();
    }

    public function test_the_limit_is_per_username_and_ip(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.store'), ['username' => 'giarfa', 'password' => 'wrong']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(route('login.store'), ['username' => 'giarfa', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_a_successful_sign_in_clears_the_failed_attempts(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        $this->post(route('login.store'), ['username' => 'giarfa', 'password' => 'wrong']);
        $this->post(route('login.store'), ['username' => 'giarfa', 'password' => self::PASSWORD]);

        $this->assertSame(0, RateLimiter::attempts('giarfa|127.0.0.1'));
    }

    public function test_signing_in_regenerates_the_session_id_and_sets_no_remember_cookie(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        $this->get(route('login'));
        $before = session()->getId();

        $response = $this->post(route('login.store'), ['username' => 'giarfa', 'password' => self::PASSWORD]);

        $this->assertNotSame($before, session()->getId());

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringStartsNotWith('remember_web_', $cookie->getName());
        }
    }

    public function test_an_in_app_intended_url_is_honoured(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        $this->withSession(['url.intended' => url('/import/garmin')])
            ->post(route('login.store'), ['username' => 'giarfa', 'password' => self::PASSWORD])
            ->assertRedirect(url('/import/garmin'));
    }

    public function test_an_off_host_intended_url_is_ignored(): void
    {
        User::factory()->ownerWithPassword(self::PASSWORD)->create();

        foreach (['https://evil.example/steal', url('/').'.evil.example/x', '//evil.example'] as $intended) {
            $this->withSession(['url.intended' => $intended])
                ->post(route('login.store'), ['username' => 'giarfa', 'password' => self::PASSWORD])
                ->assertRedirect(route('dashboard'));

            auth()->logout();
        }
    }

    public function test_a_signed_in_owner_opening_the_sign_in_page_goes_to_the_dashboard(): void
    {
        $owner = User::factory()->ownerWithPassword(self::PASSWORD)->create();

        $this->actingAs($owner)->get(route('login'))->assertRedirect(route('dashboard'));
    }

    public function test_the_username_is_required(): void
    {
        $this->post(route('login.store'), ['password' => 'x'])
            ->assertSessionHasErrors(['username' => 'Enter your username.']);
    }
}
