<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A coherent 16-week training block for one fixed demo athlete: the owner account
 * `giarfa` with no password yet, so a seeded database starts at the first sign-in.
 *
 * The block is always computed relative to the current week, so it can never drift
 * out of the window the dashboard renders. Its shape is deliberate: an illness week
 * and a peak week give the intensity scale a real distribution to bucket, and the
 * current week stays partial so future days render as out-of-range.
 *
 * Every run is what a Garmin Connect CSV import writes. Before the block sit seven
 * weeks of history, so Previous has something to show after `migrate:fresh --seed`
 * (US-013). Its weekly totals are the ones the US-014 mockup draws, so both specs
 * share one fixture.
 */
class TrainingBlockSeeder extends Seeder
{
    private const BLOCK_WEEKS = 16;

    private const ILLNESS_WEEK_INDEX = 9;

    private const PEAK_WEEK_INDEX = 12;

    /**
     * Weeks that carry a day with two runs, so the dashboard has a case to display.
     *
     * @var array<int, int>
     */
    private const DOUBLE_RUN_WEEK_INDEXES = [2, 6, 10];

    /**
     * How many runs were recorded without a heart-rate strap, as a percentage.
     */
    private const WITHOUT_HEART_RATE_PERCENT = 15;

    /**
     * How many of the block's first weeks come from an older export whose metric columns
     * hold `--`, so the demo shows a run that lacks every metric.
     */
    private const EXPORT_WITHOUT_METRICS_WEEKS = 2;

    /**
     * Weekly distance of the seven weeks right before the block, oldest first. The sixth
     * is a rest week (a calf strain), which the trend and the grid must both show as 0 km
     * rather than skip.
     *
     * @var array<int, float>
     */
    private const HISTORY_WEEKLY_KILOMETRES = [28.4, 31.0, 33.5, 36.2, 22.0, 0.0, 18.6];

    public function run(): void
    {
        // This seeder fabricates an athlete and a few dozen activities. Running it
        // against a real database — `db:seed --force` is routine in deploy scripts —
        // would inject a demo account into production data.
        if (! app()->environment(['local', 'testing'])) {
            $this->command->warn('TrainingBlockSeeder skipped: demo data is only seeded in local and testing environments.');

            return;
        }

        /*
         * The username migration already creates the owner on an empty database,
         * and `username` is unique: a second `giarfa` would throw. The demo athlete
         * becomes that row when it exists.
         */
        $athlete = User::query()->where('username', User::OWNER_USERNAME)->first()
            ?? User::factory()->owner()->make();

        $athlete->forceFill(User::factory()->raw([
            'name' => 'Demo Athlete',
            'email' => 'athlete@sport-heatmap.test',
            'username' => User::OWNER_USERNAME,
            'password' => null,
        ]))->save();

        foreach ($this->trainingBlock() as $weekIndex => $runs) {
            foreach ($runs as $run) {
                $this->createActivity(
                    $athlete,
                    $run['date'],
                    $run['distance_meters'],
                    hasMetrics: $weekIndex >= self::EXPORT_WITHOUT_METRICS_WEEKS,
                );
            }
        }

        foreach ($this->historyBeforeBlock() as $run) {
            $this->createActivity($athlete, $run['date'], $run['distance_meters'], hasMetrics: false);
        }
    }

    /**
     * Garmin-imported runs in the seven weeks before the block's first week, each week
     * summing to exactly its total in HISTORY_WEEKLY_KILOMETRES. A week of 0 km has no run.
     *
     * @return array<int, array{date: Carbon, distance_meters: int}>
     */
    private function historyBeforeBlock(): array
    {
        $blockStart = Carbon::today()->startOfWeek()->subWeeks(self::BLOCK_WEEKS - 1);
        $weekCount = count(self::HISTORY_WEEKLY_KILOMETRES);

        $runs = [];

        foreach (self::HISTORY_WEEKLY_KILOMETRES as $position => $kilometres) {
            $totalMeters = (int) round($kilometres * 1_000);

            if ($totalMeters === 0) {
                continue;
            }

            $weekStart = $blockStart->copy()->subWeeks($weekCount - $position);
            $days = $this->runningDays($weekStart, $totalMeters >= 30_000 ? 4 : 3);

            foreach ($this->splitDistance($totalMeters, count($days)) as $index => $distanceMeters) {
                $runs[] = ['date' => $days[$index], 'distance_meters' => $distanceMeters];
            }
        }

        return $runs;
    }

    /**
     * Split a weekly total into runs of similar size that add up to it exactly.
     *
     * @return array<int, int>
     */
    private function splitDistance(int $totalMeters, int $parts): array
    {
        $weights = array_map(static fn (): int => fake()->numberBetween(80, 120), range(1, $parts));

        $distances = array_map(
            static fn (int $weight): int => intdiv($totalMeters * $weight, array_sum($weights)),
            $weights,
        );

        $distances[$parts - 1] += $totalMeters - array_sum($distances);

        return $distances;
    }

    /**
     * Build every planned run of the block, week by week.
     *
     * @return array<int, array<int, array{date: Carbon, distance_meters: int}>>
     */
    private function trainingBlock(): array
    {
        $currentWeekStart = Carbon::today()->startOfWeek();

        $block = [];

        for ($weekIndex = 0; $weekIndex < self::BLOCK_WEEKS; $weekIndex++) {
            $weekStart = $currentWeekStart->copy()->subWeeks(self::BLOCK_WEEKS - 1 - $weekIndex);

            $block[$weekIndex] = match (true) {
                $weekIndex === self::ILLNESS_WEEK_INDEX => $this->illnessWeek($weekStart),
                $weekIndex === self::PEAK_WEEK_INDEX => $this->peakWeek($weekStart),
                $weekIndex === self::BLOCK_WEEKS - 1 => $this->currentPartialWeek($weekStart),
                default => $this->normalWeek($weekStart, $weekIndex),
            };
        }

        return $this->raisePeakAboveEveryOtherWeek($block);
    }

    /**
     * Three to six running days, one of them a long run once the week is big enough.
     *
     * @return array<int, array{date: Carbon, distance_meters: int}>
     */
    private function normalWeek(Carbon $weekStart, int $weekIndex): array
    {
        $days = $this->runningDays($weekStart, fake()->numberBetween(3, 6));

        $runs = [];

        foreach ($days as $position => $day) {
            $isLongRun = $position === count($days) - 1 && count($days) >= 4;

            $runs[] = [
                'date' => $day,
                'distance_meters' => $isLongRun
                    ? fake()->numberBetween(15_000, 19_000)
                    : fake()->numberBetween(6_000, 13_000),
            ];
        }

        if (in_array($weekIndex, self::DOUBLE_RUN_WEEK_INDEXES, true)) {
            $runs[] = [
                'date' => $days[0],
                'distance_meters' => fake()->numberBetween(4_000, 7_000),
            ];
        }

        return $runs;
    }

    /**
     * A single short run: the athlete was ill.
     *
     * @return array<int, array{date: Carbon, distance_meters: int}>
     */
    private function illnessWeek(Carbon $weekStart): array
    {
        return [[
            'date' => $weekStart->copy()->addDays(fake()->numberBetween(4, 6)),
            'distance_meters' => fake()->numberBetween(3_000, 5_000),
        ]];
    }

    /**
     * Seven running days including a long run, plus a double day.
     *
     * @return array<int, array{date: Carbon, distance_meters: int}>
     */
    private function peakWeek(Carbon $weekStart): array
    {
        $days = $this->runningDays($weekStart, 7);

        $runs = [];

        foreach ($days as $position => $day) {
            $runs[] = [
                'date' => $day,
                'distance_meters' => $position === count($days) - 1
                    ? fake()->numberBetween(22_000, 26_000)
                    : fake()->numberBetween(11_000, 16_000),
            ];
        }

        $runs[] = [
            'date' => $days[1],
            'distance_meters' => fake()->numberBetween(5_000, 8_000),
        ];

        return $runs;
    }

    /**
     * The in-progress week: runs only up to and including today, never in the future.
     *
     * @return array<int, array{date: Carbon, distance_meters: int}>
     */
    private function currentPartialWeek(Carbon $weekStart): array
    {
        $elapsedDays = Carbon::today()->dayOfWeekIso;

        $days = $this->runningDays(
            $weekStart,
            min(fake()->numberBetween(2, 4), $elapsedDays),
            $elapsedDays,
        );

        return array_map(static fn (Carbon $day): array => [
            'date' => $day,
            'distance_meters' => fake()->numberBetween(6_000, 13_000),
        ], $days);
    }

    /**
     * Pick distinct, chronologically ordered running days inside a week.
     *
     * @return array<int, Carbon>
     */
    private function runningDays(Carbon $weekStart, int $count, ?int $availableDays = null): array
    {
        $offsets = range(0, ($availableDays ?? 7) - 1);
        shuffle($offsets);

        $chosen = array_slice($offsets, 0, max($count, 1));
        sort($chosen);

        return array_map(
            static fn (int $offset): Carbon => $weekStart->copy()->addDays($offset),
            $chosen,
        );
    }

    /**
     * Guarantee the peak week really is the highest-volume week of the block, whatever
     * the random draws produced.
     *
     * @param  array<int, array<int, array{date: Carbon, distance_meters: int}>>  $block
     * @return array<int, array<int, array{date: Carbon, distance_meters: int}>>
     */
    private function raisePeakAboveEveryOtherWeek(array $block): array
    {
        $peakTotal = $this->weeklyTotal($block[self::PEAK_WEEK_INDEX]);

        $highestOtherTotal = 0;

        foreach ($block as $weekIndex => $runs) {
            if ($weekIndex !== self::PEAK_WEEK_INDEX) {
                $highestOtherTotal = max($highestOtherTotal, $this->weeklyTotal($runs));
            }
        }

        if ($peakTotal <= $highestOtherTotal) {
            $longestRunIndex = $this->longestRunIndex($block[self::PEAK_WEEK_INDEX]);

            $block[self::PEAK_WEEK_INDEX][$longestRunIndex]['distance_meters']
                += $highestOtherTotal - $peakTotal + fake()->numberBetween(5_000, 10_000);
        }

        return $block;
    }

    /**
     * @param  array<int, array{date: Carbon, distance_meters: int}>  $runs
     */
    private function weeklyTotal(array $runs): int
    {
        return (int) array_sum(array_column($runs, 'distance_meters'));
    }

    /**
     * @param  array<int, array{date: Carbon, distance_meters: int}>  $runs
     */
    private function longestRunIndex(array $runs): int
    {
        $distances = array_column($runs, 'distance_meters');

        return (int) array_search(max($distances), $distances, true);
    }

    /**
     * One run as a Garmin import writes it. The history before the block, and the block's
     * first weeks, come from an older export whose metric columns hold `--`, so those runs
     * have none; a few later runs lack a heart rate, as a run without a strap does.
     */
    private function createActivity(User $athlete, Carbon $date, int $distanceMeters, bool $hasMetrics = true): void
    {
        $factory = Activity::factory()->for($athlete)->onDate($date);

        $factory = match (true) {
            fake()->boolean(15) => $factory->trailRun(),
            fake()->boolean(12) => $factory->virtualRun(),
            default => $factory,
        };

        // Applied last, so Garmin's place-and-type name wins over the sport-type state's.
        $factory = $factory->garminImported();

        $factory = match (true) {
            ! $hasMetrics => $factory->withoutMetrics(),
            fake()->boolean(self::WITHOUT_HEART_RATE_PERCENT) => $factory->withoutHeartRate(),
            default => $factory,
        };

        $secondsPerKilometre = fake()->numberBetween(255, 375);

        $attributes = [
            'distance_meters' => $distanceMeters,
            'moving_time_seconds' => (int) round($distanceMeters / 1_000 * $secondsPerKilometre),
        ];

        if ($hasMetrics) {
            $attributes['average_pace_seconds_per_km'] = $secondsPerKilometre;
        }

        $factory->create($attributes);
    }
}
