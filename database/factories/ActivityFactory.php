<?php

namespace Database\Factories;

use App\Enums\ActivitySource;
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
            'source' => ActivitySource::GarminCsv,
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
     * A flat run for the aerobic-efficiency measure: under 5 m of climb per km. The ascent
     * is derived from the distance already built, so set the distance with a state or with
     * make()/create() attributes passed to `state()` before this one: a `create()` override
     * is applied last and would leave the ascent describing a different run.
     */
    public function flat(): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_ascent_meters' => fake()->numberBetween(0, (int) floor(4.9 * $this->kilometres($attributes))),
        ]);
    }

    /**
     * A hilly run: 5 to 10 m of climb per km, the band FR-023 converts to a flat equivalent.
     */
    public function hilly(): static
    {
        return $this->state(function (array $attributes): array {
            $kilometres = $this->kilometres($attributes);
            $least = (int) ceil(5.1 * $kilometres);

            return ['total_ascent_meters' => fake()->numberBetween($least, max($least, (int) floor(9.9 * $kilometres)))];
        });
    }

    /**
     * A steep run: 11 to 15 m of climb per km, drawn by the card as a cross and never counted.
     */
    public function steep(): static
    {
        return $this->state(function (array $attributes): array {
            $kilometres = $this->kilometres($attributes);
            $least = (int) ceil(11 * $kilometres);

            return ['total_ascent_meters' => fake()->numberBetween($least, max($least, (int) floor(15 * $kilometres)))];
        });
    }

    /**
     * A run at a given average heart rate.
     */
    public function atHeartRate(int $beatsPerMinute): static
    {
        return $this->state(fn (array $attributes): array => [
            'average_heart_rate' => $beatsPerMinute,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function kilometres(array $attributes): float
    {
        return (int) $attributes['distance_meters'] / 1_000;
    }

    /**
     * A run carrying the extended metrics both Garmin sources record, every value
     * derived from the run's own distance, moving time, and pace so the record never
     * contradicts itself: the elapsed time is never shorter than the moving time, the
     * best pace is faster than the average, the steps follow the cadence, the descent
     * follows the ascent, and the lowest point is never above the highest. One-decimal
     * values are rounded here, the way the import boundary rounds them.
     */
    public function withExtendedMetrics(): static
    {
        return $this->state(function (array $attributes): array {
            $distanceMeters = (int) $attributes['distance_meters'];
            $movingSeconds = (int) $attributes['moving_time_seconds'];
            $averagePace = $movingSeconds / ($distanceMeters / 1_000);
            $averageCadence = fake()->numberBetween(158, 180);
            $steps = (int) round($averageCadence * $movingSeconds / 60);
            $ascent = (int) ($attributes['total_ascent_meters'] ?? fake()->numberBetween(5, 180));
            $lowestPoint = fake()->numberBetween(60, 300);

            return [
                'calories' => (int) round($distanceMeters / 1_000 * fake()->numberBetween(58, 72)),
                'max_heart_rate' => min(250, (int) ($attributes['average_heart_rate'] ?? 150) + fake()->numberBetween(12, 28)),
                'aerobic_training_effect' => round(fake()->randomFloat(1, 2.0, 4.8), 1),
                'average_running_cadence' => $averageCadence,
                'max_running_cadence' => $averageCadence + fake()->numberBetween(10, 25),
                'best_pace_seconds_per_km' => max(150, (int) round($averagePace * fake()->randomFloat(2, 0.78, 0.92))),
                'total_descent_meters' => max(0, (int) round($ascent * fake()->randomFloat(2, 0.85, 1.15))),
                'average_stride_length_cm' => min(500.0, round($distanceMeters / $steps * 100, 1)),
                'average_vertical_ratio_percent' => round(fake()->randomFloat(1, 6.0, 9.0), 1),
                'average_vertical_oscillation_cm' => round(fake()->randomFloat(1, 7.5, 10.5), 1),
                'average_ground_contact_time_ms' => fake()->numberBetween(215, 265),
                'average_ground_contact_balance_left_percent' => round(fake()->randomFloat(1, 48.0, 52.0), 1),
                'steps' => $steps,
                'lap_count' => max(1, (int) ceil($distanceMeters / 1_000)),
                'elapsed_time_seconds' => $movingSeconds + (int) round($movingSeconds * fake()->randomFloat(3, 0, 0.05)),
                'min_elevation_meters' => $lowestPoint,
                'max_elevation_meters' => $lowestPoint + max($ascent, 10),
            ];
        });
    }

    /**
     * The metrics only the Garmin Connect API carries: time in each heart-rate zone,
     * the fastest 1 km, mile, and 5 km, and the place name. The zones never add up to
     * more than the elapsed time, and a split exists only when the run covered it.
     */
    public function withApiOnlyMetrics(): static
    {
        return $this->state(function (array $attributes): array {
            $distanceMeters = (int) $attributes['distance_meters'];
            $movingSeconds = (int) $attributes['moving_time_seconds'];
            $elapsedSeconds = (int) ($attributes['elapsed_time_seconds'] ?? $movingSeconds);
            $averagePace = $movingSeconds / ($distanceMeters / 1_000);

            $zoneShares = [0.05, 0.35, 0.35, 0.2, 0.05];
            $zones = [];

            foreach ($zoneShares as $index => $share) {
                $zones['hr_zone_'.($index + 1).'_seconds'] = (int) floor($elapsedSeconds * $share * fake()->randomFloat(2, 0.8, 1.0));
            }

            return [
                ...$zones,
                'fastest_1k_seconds' => $distanceMeters >= 1_000 ? (int) round($averagePace * fake()->randomFloat(2, 0.82, 0.93)) : null,
                'fastest_mile_seconds' => $distanceMeters >= 1_609 ? (int) round($averagePace * 1.609 * fake()->randomFloat(2, 0.85, 0.95)) : null,
                'fastest_5k_seconds' => $distanceMeters >= 5_000 ? (int) round($averagePace * 5 * fake()->randomFloat(2, 0.9, 0.97)) : null,
                'location_name' => fake()->randomElement(['Lazise', 'Verona', 'Bolzano']),
            ];
        });
    }

    /**
     * A run whose Garmin CSV row carried a Training Stress Score: the one extended metric
     * the API never supplies.
     */
    public function withTrainingStressScore(): static
    {
        return $this->state(fn (array $attributes): array => [
            'training_stress_score' => round(fake()->randomFloat(1, 25.0, 190.0), 1),
        ]);
    }

    /**
     * A run imported from a Garmin Connect CSV: no Garmin activity id, a place-and-type
     * name. The default definition already is one; this states it where a test or the
     * seeder is about the source.
     */
    public function garminImported(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => fake()->randomElement(self::RUN_NAMES),
            'source' => ActivitySource::GarminCsv,
            'garmin_activity_id' => null,
        ]);
    }

    /**
     * A run the Garmin Connect API supplied: it carries its Garmin activity id, a
     * realistic 11-digit one, unique within the process so two runs never collide on
     * the unique index.
     */
    public function garminSynced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => ActivitySource::GarminApi,
            'garmin_activity_id' => fake()->unique()->numberBetween(10_000_000_000, 99_999_999_999),
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
