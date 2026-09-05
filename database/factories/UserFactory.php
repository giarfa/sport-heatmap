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
     * There is no password login in this product — identity comes from Strava —
     * so the default state leaves both the password and the Strava columns null.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
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
}
