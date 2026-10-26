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
     * The default state is not the owner: it has no username and no password. Use
     * owner() or ownerWithPassword() for the sign-in account.
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
}
