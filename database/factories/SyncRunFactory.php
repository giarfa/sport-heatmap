<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state: a healthy run that finished a few hours ago.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subHours(fake()->numberBetween(2, 10));

        return [
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addSeconds(fake()->numberBetween(3, 45)),
            'status' => SyncRunStatus::Succeeded,
            'activities_upserted' => fake()->numberBetween(0, 8),
            'activities_deleted' => fake()->numberBetween(0, 1),
            'error_message' => null,
        ];
    }

    /**
     * A run that finished with an error.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncRunStatus::Failed,
            'activities_upserted' => 0,
            'activities_deleted' => 0,
            'error_message' => fake()->randomElement([
                'Strava API returned 401 Unauthorized: refresh token rejected.',
                'Strava API rate limit exceeded (429).',
                'Connection to www.strava.com timed out after 30 seconds.',
            ]),
        ]);
    }

    /**
     * A run still in flight: no finish time yet.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => now()->subSeconds(fake()->numberBetween(1, 30)),
            'finished_at' => null,
            'status' => SyncRunStatus::Running,
            'activities_upserted' => 0,
            'activities_deleted' => 0,
            'error_message' => null,
        ]);
    }

    /**
     * A successful run old enough to trip the FR-007 staleness warning (48 hours).
     */
    public function stale(): static
    {
        return $this->state(function (array $attributes): array {
            $startedAt = now()->subHours(fake()->numberBetween(49, 96));

            return [
                'started_at' => $startedAt,
                'finished_at' => $startedAt->copy()->addSeconds(fake()->numberBetween(3, 45)),
            ];
        });
    }
}
