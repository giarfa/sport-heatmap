<?php

namespace Database\Factories;

use App\Enums\SportType;
use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Distance and moving time are always derived from one another at a plausible
     * pace, and activity_date is always derived from start_date_local, so a
     * generated record can never contradict itself.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $distanceMeters = fake()->numberBetween(4_000, 25_000);
        $secondsPerKilometre = fake()->numberBetween(255, 375);
        $startDateLocal = Carbon::instance(fake()->dateTimeBetween('-112 days', 'now'));

        return [
            'strava_activity_id' => fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'user_id' => User::factory(),
            'sport_type' => SportType::Run,
            'name' => fake()->randomElement([
                'Morning Run',
                'Easy shakeout',
                'Long run',
                'Lunch Run',
                'Evening Run',
                'Tempo session',
                'Recovery jog',
                'Intervals on the track',
            ]),
            'distance_meters' => $distanceMeters,
            'moving_time_seconds' => (int) round($distanceMeters / 1_000 * $secondsPerKilometre),
            'start_date_local' => $startDateLocal,
            'activity_date' => $startDateLocal->toDateString(),
        ];
    }

    /**
     * A trail run.
     */
    public function trailRun(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sport_type' => SportType::TrailRun,
            'name' => fake()->randomElement(['Trail loop', 'Hill repeats on the ridge', 'Forest run']),
        ]);
    }

    /**
     * A treadmill or virtual platform run.
     */
    public function virtualRun(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sport_type' => SportType::VirtualRun,
            'name' => fake()->randomElement(['Treadmill run', 'Virtual easy run', 'Indoor progression']),
        ]);
    }

    /**
     * Place the activity on a specific calendar day, keeping the time of day random.
     */
    public function onDate(CarbonInterface $date): static
    {
        return $this->state(function (array $attributes) use ($date): array {
            $startDateLocal = $date->copy()
                ->startOfDay()
                ->addHours(fake()->numberBetween(6, 20))
                ->addMinutes(fake()->numberBetween(0, 59));

            return [
                'start_date_local' => $startDateLocal,
                'activity_date' => $startDateLocal->toDateString(),
            ];
        });
    }
}
