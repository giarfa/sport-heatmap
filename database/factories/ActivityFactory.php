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
     * Garmin names a run after its place and its type.
     *
     * @var array<int, string>
     */
    private const RUN_NAMES = ['Paese Corsa', 'Verona Corsa', 'Bolzano Corsa', 'Lazise Corsa'];

    /**
     * Define the model's default state.
     *
     * Distance and moving time are always derived from one another at a plausible
     * pace, the stored average pace is that same pace, and activity_date is always
     * derived from start_date_local, so a generated record can never contradict itself.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $distanceMeters = fake()->numberBetween(4_000, 25_000);
        $secondsPerKilometre = fake()->numberBetween(255, 375);
        $startDateLocal = Carbon::instance(fake()->dateTimeBetween('-112 days', 'now'));

        return [
            'user_id' => User::factory(),
            'sport_type' => SportType::Run,
            'name' => fake()->randomElement(self::RUN_NAMES),
            'distance_meters' => $distanceMeters,
            'moving_time_seconds' => (int) round($distanceMeters / 1_000 * $secondsPerKilometre),
            'start_date_local' => $startDateLocal,
            'activity_date' => $startDateLocal->toDateString(),
            'average_pace_seconds_per_km' => $secondsPerKilometre,
            'total_ascent_meters' => fake()->numberBetween(5, 180),
            'average_heart_rate' => fake()->numberBetween(135, 172),
        ];
    }

    /**
     * A run with none of the optional metrics, like every row of a Garmin export
     * whose pace, ascent and heart rate columns hold `--`.
     */
    public function withoutMetrics(): static
    {
        return $this->state(fn (array $attributes): array => [
            'average_pace_seconds_per_km' => null,
            'total_ascent_meters' => null,
            'average_heart_rate' => null,
        ]);
    }

    /**
     * A run recorded without a heart-rate strap: pace and ascent are there, heart
     * rate is not.
     */
    public function withoutHeartRate(): static
    {
        return $this->state(fn (array $attributes): array => [
            'average_heart_rate' => null,
        ]);
    }

    /**
     * A run imported from a Garmin Connect CSV, which is what every stored run is. The
     * default definition already is one; this reads better where a test is about the
     * import.
     */
    public function garminImported(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => fake()->randomElement(self::RUN_NAMES),
        ]);
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
