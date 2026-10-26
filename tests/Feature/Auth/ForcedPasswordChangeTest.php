<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'a much longer passphrase';

    public function test_the_owner_with_no_password_signs_in_with_an_empty_password(): void
    {
        User::factory()->owner()->create();

        $this->post(route('login.store'), ['username' => 'giarfa', 'password' => ''])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_a_non_empty_password_is_refused_while_none_is_stored(): void
    {
        User::factory()->owner()->create();

        $this->post(route('login.store'), ['username' => 'giarfa', 'password' => 'guessing'])
            ->assertSessionHasErrors(['username' => 'These credentials do not match our records.']);

        $this->assertGuest();
    }

    public function test_every_route_but_the_change_page_and_sign_out_leads_to_the_change_page(): void
    {
        $owner = User::factory()->owner()->create();

        foreach ([route('dashboard'), route('dashboard', ['to' => '2026-09-06']), route('garmin.import.create'), route('garmin.import.review'), route('garmin.import.summary')] as $url) {
            $this->actingAs($owner)->get($url)->assertRedirect(route('password.change'));
        }

        $this->actingAs($owner)->get(route('password.change'))->assertOk();
        $this->actingAs($owner)->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_the_change_page_renders_labelled_fields_and_a_sign_out_button(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->get(route('password.change'));

        $response->assertOk();
        $response->assertSee('Set your password');
        $response->assertSee('<label for="password"', escape: false);
        $response->assertSee('<label for="password_confirmation"', escape: false);
        $response->assertSee('autocomplete="new-password"', escape: false);
        $response->assertSee('aria-describedby="password-hint"', escape: false);
        $response->assertSee('action="'.route('logout').'"', escape: false);
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
    }

    public function test_an_invalid_new_password_is_refused_at_the_field_and_nothing_is_stored(): void
    {
        $owner = User::factory()->owner()->create();

        $cases = [
            'empty' => [['password' => '', 'password_confirmation' => ''], 'Enter a new password.'],
            'too short' => [['password' => '12345678901', 'password_confirmation' => '12345678901'], 'Use at least 12 characters.'],
            'mismatch' => [['password' => self::NEW_PASSWORD, 'password_confirmation' => 'something else entirely'], 'The two passwords do not match.'],
        ];

        foreach ($cases as [$input, $message]) {
            $this->actingAs($owner)->from(route('password.change'))
                ->put(route('password.update'), $input)
                ->assertRedirect(route('password.change'))
                ->assertSessionHasErrors(['password' => $message]);

            $this->assertNull($owner->fresh()->password);
        }
    }

    public function test_a_valid_password_is_stored_hashed_the_session_regenerates_and_the_owner_lands_on_the_dashboard(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('password.change'));
        $before = session()->getId();

        $this->put(route('password.update'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertRedirect(route('dashboard'));

        $stored = $owner->fresh()->password;
        $this->assertNotSame(self::NEW_PASSWORD, $stored);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $stored));
        $this->assertNotSame($before, session()->getId());
    }

    public function test_once_a_password_is_set_an_empty_password_is_refused_and_nothing_is_forced(): void
    {
        $owner = User::factory()->ownerWithPassword(self::NEW_PASSWORD)->create();

        $this->post(route('login.store'), ['username' => 'giarfa', 'password' => ''])
            ->assertSessionHasErrors(['username' => 'These credentials do not match our records.']);
        $this->assertGuest();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $this->assertFalse($response->isRedirect(route('password.change')));
    }

    public function test_the_change_page_closes_once_a_password_is_set(): void
    {
        $owner = User::factory()->ownerWithPassword(self::NEW_PASSWORD)->create();

        $this->actingAs($owner)->get(route('password.change'))->assertRedirect(route('dashboard'));
    }

    public function test_a_password_that_exists_is_never_replaced_through_the_update_route(): void
    {
        $owner = User::factory()->ownerWithPassword(self::NEW_PASSWORD)->create();
        $storedHash = $owner->password;

        $this->actingAs($owner)
            ->put(route('password.update'), ['password' => 'another long passphrase', 'password_confirmation' => 'another long passphrase'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($storedHash, $owner->fresh()->password);
    }

    public function test_any_other_username_is_refused_with_the_generic_error(): void
    {
        User::factory()->owner()->create();

        foreach (['admin', 'GIARFA'] as $username) {
            $this->post(route('login.store'), ['username' => $username, 'password' => ''])
                ->assertSessionHasErrors(['username' => 'These credentials do not match our records.']);

            $this->assertGuest();
        }
    }

    public function test_the_change_routes_need_a_session(): void
    {
        $this->get(route('password.change'))->assertRedirect(route('login'));
        $this->put(route('password.update'), [])->assertRedirect(route('login'));
    }
}
