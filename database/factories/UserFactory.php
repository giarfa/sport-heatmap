<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default state is not the owner: it has no username, no password, and no
     * Strava identity. Use owner() or ownerWithPassword() for the sign-in account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => null,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => null,
            'remember_token' => Str::random(10),
            'strava_athlete_id' => null,
            'strava_access_token' => null,
            'strava_refresh_token' => null,
            'strava_token_expires_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is the owner in the first-sign-in state: the
     * username is set and the password is still null.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes): array => [
            'username' => User::OWNER_USERNAME,
            'password' => null,
        ]);
    }

    /**
     * Indicate that the owner has already set a password.
     */
    public function ownerWithPassword(string $plain = 'correct horse battery'): static
    {
        return $this->owner()->state(fn (array $attributes): array => [
            'password' => $plain,
        ]);
    }

    /**
     * Indicate that the athlete has no Strava identity and no tokens — the
     * placeholder row a CSV-only import used to create.
     */
    public function csvOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Garmin CSV athlete',
            'email' => 'csv-athlete@garmin.invalid',
            'email_verified_at' => null,
            'password' => null,
            'strava_athlete_id' => null,
            'strava_access_token' => null,
            'strava_refresh_token' => null,
            'strava_token_expires_at' => null,
        ]);
    }

    /**
     * Indicate that the athlete has connected Strava with a live access token.
     */
    public function stravaConnected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'strava_athlete_id' => (string) fake()->unique()->numberBetween(1_000_000, 99_999_999),
            'strava_access_token' => Str::random(40),
            'strava_refresh_token' => Str::random(40),
            'strava_token_expires_at' => now()->addHours(6),
        ]);
    }

    /**
     * Indicate that the athlete's Strava access token has already expired.
     */
    public function stravaTokenExpired(): static
    {
        return $this->stravaConnected()->state(fn (array $attributes): array => [
            'strava_token_expires_at' => now()->subHour(),
        ]);
    }

    /**
     * Indicate that the token expires inside the refresh safety margin.
     *
     * Not yet expired, but close enough that it must be refreshed before use —
     * this is the branch that distinguishes the margin from a hard expiry.
     */
    public function stravaTokenExpiringSoon(): static
    {
        $margin = (int) config('strava.token_refresh_margin_seconds');

        return $this->stravaConnected()->state(fn (array $attributes): array => [
            'strava_token_expires_at' => now()->addSeconds(max(1, intdiv($margin, 2))),
        ]);
    }
}
