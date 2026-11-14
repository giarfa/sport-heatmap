<?php

namespace Database\Factories;

use App\Enums\GarminSyncFailure;
use App\Enums\GarminSyncOutcome;
use App\Enums\GarminSyncTrigger;
use App\Models\GarminSync;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<GarminSync>
 */
class GarminSyncFactory extends Factory
{
    /**
     * Define the model's default state: a scheduled sync that finished a few minutes
     * ago and found a handful of new runs. The start always precedes the end.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $finishedAt = Carbon::instance(fake()->dateTimeBetween('-3 days', '-1 hour'));

        return [
            'trigger' => GarminSyncTrigger::Scheduled,
            'started_at' => $finishedAt->copy()->subSeconds(fake()->numberBetween(2, 40)),
            'finished_at' => $finishedAt,
            'outcome' => GarminSyncOutcome::Succeeded,
            'failure_cause' => null,
            'full_history' => false,
            'new_count' => fake()->numberBetween(0, 3),
            'updated_count' => fake()->numberBetween(0, 2),
            'linked_count' => 0,
            'ignored_count' => fake()->numberBetween(0, 4),
            'future_dated_count' => 0,
            'invalid_count' => 0,
        ];
    }

    /**
     * An attempt that ended well.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => GarminSyncOutcome::Succeeded,
            'failure_cause' => null,
        ]);
    }

    /**
     * An attempt that ended with the given cause and wrote nothing.
     */
    public function failed(GarminSyncFailure $cause = GarminSyncFailure::Unreachable): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => GarminSyncOutcome::Failed,
            'failure_cause' => $cause,
            'new_count' => 0,
            'updated_count' => 0,
            'linked_count' => 0,
            'ignored_count' => 0,
            'future_dated_count' => 0,
        ]);
    }

    /**
     * An attempt still in flight: no end time, no verdict, nothing counted yet.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => Carbon::now()->subSeconds(fake()->numberBetween(1, 10)),
            'finished_at' => null,
            'outcome' => GarminSyncOutcome::Running,
            'failure_cause' => null,
            'new_count' => 0,
            'updated_count' => 0,
            'linked_count' => 0,
            'ignored_count' => 0,
            'future_dated_count' => 0,
        ]);
    }

    /**
     * Started by the owner's Sync now button.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'trigger' => GarminSyncTrigger::Manual,
        ]);
    }

    /**
     * Started by the daily schedule.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'trigger' => GarminSyncTrigger::Scheduled,
        ]);
    }

    /**
     * An attempt that read the whole history rather than the last few days.
     */
    public function fullHistory(): static
    {
        return $this->state(fn (array $attributes): array => [
            'full_history' => true,
            'new_count' => fake()->numberBetween(60, 140),
            'linked_count' => fake()->numberBetween(20, 55),
            'ignored_count' => fake()->numberBetween(10, 60),
        ]);
    }
}
