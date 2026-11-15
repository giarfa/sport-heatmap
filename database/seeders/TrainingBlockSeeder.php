<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\GarminSync;
use App\Models\User;
use Database\Factories\ActivityFactory;
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
 * The block's last four weeks are what the Garmin Connect sync writes: those runs carry a
 * Garmin activity id, and one succeeded full-history scheduled sync finished this
 * morning, so the dashboard shows "last sync" out of the box. Everything older is what a
 * Garmin Connect CSV import writes. Before the block sit seven
 * weeks of history, so Previous has something to show after `migrate:fresh --seed`
 * (US-013). Its weekly totals are the ones the US-014 mockup draws, so both specs
 * share one fixture.
 *
 * The aerobic-efficiency card (US-024) reads pace and heart rate, so the block's runs with
 * metrics carry a heart rate around 148 bpm and a pace that improves across the 16 weeks at
 * that heart rate; about a fifth are hilly, one is steep, one is more than 10 bpm above the
 * block's median, and one is under 3 km. The same weeks one year earlier, a little slower and
 * with a gap in the middle, give last year's dashed line a place to break.
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
     * How many CSV-imported runs carry a Training Stress Score, as a percentage.
     */
    private const WITH_TRAINING_STRESS_SCORE_PERCENT = 20;

    /**
     * How many of the block's first weeks come from an older export whose metric columns
     * hold `--`, so the demo shows a run that lacks every metric.
     */
    private const EXPORT_WITHOUT_METRICS_WEEKS = 2;

    /**
     * How many of the block's last weeks, the current one included, the Garmin sync wrote.
     */
    private const API_SYNCED_WEEKS = 4;

    /**
     * The heart rate the demo athlete runs easy at, and so the block's median.
     */
    private const EASY_HEART_RATE = 148;

    /**
     * A run this far above the median heart rate is the outlier FR-023 leaves out.
     */
    private const OUTLIER_HEART_RATE = 162;

    /**
     * Seconds per km at the easy heart rate in the block's first week, and how much faster
     * each later week is: a slow improvement the card's line should show.
     */
    private const FIRST_WEEK_PACE_AT_EASY_HEART_RATE = 372.0;

    private const WEEKLY_IMPROVEMENT_SECONDS = 1.6;

    /**
     * Last year's weeks are slower at the same heart rate.
     */
    private const LAST_YEAR_FIRST_WEEK_PACE = 390.0;

    private const LAST_YEAR_WEEKLY_IMPROVEMENT_SECONDS = 1.2;

    /**
     * The weeks of the year-earlier block that hold no run, so last year's line breaks there.
     *
     * @var array<int, int>
     */
    private const LAST_YEAR_GAP_WEEKS = [6, 7, 8, 9];

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

        $apiSyncedFrom = Carbon::today()->startOfWeek()->subWeeks(self::API_SYNCED_WEEKS - 1);
        $apiSyncedRuns = 0;

        foreach ($this->trainingBlock() as $weekIndex => $runs) {
            foreach ($runs as $runIndex => $run) {
                $isApiSynced = $run['date']->greaterThanOrEqualTo($apiSyncedFrom);
                $apiSyncedRuns += (int) $isApiSynced;

                $this->createActivity(
                    $athlete,
                    $run['date'],
                    $run['distance_meters'],
                    hasMetrics: $weekIndex >= self::EXPORT_WITHOUT_METRICS_WEEKS,
                    isApiSynced: $isApiSynced,
                    paceAtEasyHeartRate: self::FIRST_WEEK_PACE_AT_EASY_HEART_RATE - self::WEEKLY_IMPROVEMENT_SECONDS * $weekIndex,
                    role: $this->roleOf($weekIndex, $runIndex),
                );
            }
        }

        $this->seedYearEarlier($athlete);

        foreach ($this->historyBeforeBlock() as $run) {
            $this->createActivity($athlete, $run['date'], $run['distance_meters'], hasMetrics: false);
        }

        $this->createFullHistorySync($apiSyncedRuns);
    }

    /**
     * The efficiency role of one planned run: a hilly, a steep and an outlier run at fixed
     * places so every kind exists in every seeded database, and a fifth of the others hilly.
     */
    private function roleOf(int $weekIndex, int $runIndex): string
    {
        return match (true) {
            $weekIndex === 7 && $runIndex === 0 => 'hilly',
            $weekIndex === 11 && $runIndex === 0 => 'steep',
            $weekIndex === 13 && $runIndex === 0 => 'outlier',
            default => fake()->boolean(20) ? 'hilly' : 'normal',
        };
    }

    /**
     * The same weeks one year earlier, three weeks before the block included: three runs a
     * week with metrics, a little slower at the same heart rate, and a gap in the middle.
     * They sit far before the history, so Previous passes empty windows to reach them.
     */
    private function seedYearEarlier(User $athlete): void
    {
        $firstWeek = Carbon::today()->startOfWeek()->subWeeks(self::BLOCK_WEEKS - 1)->subWeeks(52);

        for ($position = -3; $position < self::BLOCK_WEEKS; $position++) {
            if (in_array($position, self::LAST_YEAR_GAP_WEEKS, true)) {
                continue;
            }

            $weekStart = $firstWeek->copy()->addWeeks($position);

            foreach ($this->runningDays($weekStart, 3) as $day) {
                $this->createActivity(
                    $athlete,
                    $day,
                    fake()->numberBetween(6_000, 13_000),
                    paceAtEasyHeartRate: self::LAST_YEAR_FIRST_WEEK_PACE - self::LAST_YEAR_WEEKLY_IMPROVEMENT_SECONDS * $position,
                    role: fake()->boolean(20) ? 'hilly' : 'normal',
                );
            }
        }
    }

    /**
     * The one attempt behind the synced runs: scheduled, full history, finished at 05:00
     * this morning — or a minute ago when the seeder runs before that, so it never ends
     * in the future.
     */
    private function createFullHistorySync(int $newRuns): void
    {
        $finishedAt = Carbon::today()->setTime(5, 0, 12)->min(Carbon::now()->subMinute());

        GarminSync::factory()->succeeded()->scheduled()->fullHistory()->create([
            'started_at' => $finishedAt->copy()->subSeconds(18),
            'finished_at' => $finishedAt,
            'new_count' => $newRuns,
            'linked_count' => 0,
        ]);
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

        /*
         * One run under 3 km, which FR-023 never counts, on a day the week already runs. It
         * goes in before the peak is raised so the peak stays the highest week whatever it adds.
         */
        $block[5][] = ['date' => $block[5][0]['date'], 'distance_meters' => 2_400];

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
     * One run as a Garmin import writes it, or as the Garmin sync does when `$isApiSynced`.
     * The history before the block, and the block's first weeks, come from an older export
     * whose metric columns hold `--`, so those runs have none; a few later runs lack a
     * heart rate, as a run without a strap does.
     *
     * A run with metrics and a `$paceAtEasyHeartRate` is shaped for the aerobic-efficiency
     * card: its heart rate sits around the easy heart rate, its pace follows from that heart
     * rate (faster when the heart rate is higher), and its climb follows `$role` — `hilly`
     * (5 to 10 m per km, so slower on the stored pace), `steep` (over 10), `outlier` (a heart
     * rate well above the median) or `normal` (flat).
     */
    private function createActivity(
        User $athlete,
        Carbon $date,
        int $distanceMeters,
        bool $hasMetrics = true,
        bool $isApiSynced = false,
        ?float $paceAtEasyHeartRate = null,
        string $role = 'normal',
    ): void {
        $factory = Activity::factory()->for($athlete)->onDate($date);

        $factory = match (true) {
            fake()->boolean(15) => $factory->trailRun(),
            fake()->boolean(12) => $factory->virtualRun(),
            default => $factory,
        };

        // Applied last, so Garmin's place-and-type name wins over the sport-type state's.
        $factory = $factory->garminImported();

        if ($isApiSynced) {
            $factory = $factory->garminSynced();
        }

        /*
         * The special roles always keep their heart rate: a steep or outlier run that lost
         * its strap would no longer show the case it exists for.
         */
        $dropsHeartRate = $role === 'normal' || $role === 'hilly'
            ? fake()->boolean(self::WITHOUT_HEART_RATE_PERCENT)
            : false;

        $factory = match (true) {
            ! $hasMetrics => $factory->withoutMetrics(),
            $dropsHeartRate => $factory->withoutHeartRate(),
            default => $factory,
        };

        if ($hasMetrics && $paceAtEasyHeartRate !== null) {
            $attributes = $this->efficiencyAttributes($factory, $distanceMeters, $paceAtEasyHeartRate, $role, $dropsHeartRate);
        } else {
            $secondsPerKilometre = fake()->numberBetween(255, 375);

            $attributes = [
                'distance_meters' => $distanceMeters,
                'moving_time_seconds' => (int) round($distanceMeters / 1_000 * $secondsPerKilometre),
            ];

            if ($hasMetrics) {
                $attributes['average_pace_seconds_per_km'] = $secondsPerKilometre;
            }
        }

        /*
         * The run's distance and time go in as a state before the extended states, not into
         * create() after them: a state closure reads the attributes built so far, and a
         * create() override is applied last, so the extended metrics would otherwise be
         * derived from the factory's random distance and contradict the run they sit on.
         */
        $factory = $factory->state($attributes);

        /*
         * Extended metrics follow where the run came from: the Garmin sync writes the
         * CSV-and-API set plus the API-only one, a CSV import the CSV set and, now and then,
         * a Training Stress Score. Runs of the older export, whose columns hold `--`, keep
         * none, so a re-import has something to enrich.
         */
        if ($hasMetrics) {
            $factory = $factory->withExtendedMetrics();

            $factory = match (true) {
                $isApiSynced => $factory->withApiOnlyMetrics(),
                fake()->boolean(self::WITH_TRAINING_STRESS_SCORE_PERCENT) => $factory->withTrainingStressScore(),
                default => $factory,
            };
        }

        $factory->create();
    }

    /**
     * The distance, time, pace, climb and heart rate of a run shaped for the efficiency card.
     *
     * The pace is chosen so the run lands near the week's pace at the easy heart rate: a
     * higher heart rate means a faster pace, and a climbing run is slower on the stored pace
     * by exactly what FR-023's flat-equivalent conversion takes back. The climb comes from
     * the factory's own states, so the seeder and the tests share one definition of
     * flat, hilly and steep.
     *
     * @return array<string, int>
     */
    private function efficiencyAttributes(ActivityFactory $factory, int $distanceMeters, float $paceAtEasyHeartRate, string $role, bool $dropsHeartRate): array
    {
        $heartRate = $role === 'outlier'
            ? self::OUTLIER_HEART_RATE
            : self::EASY_HEART_RATE + fake()->numberBetween(-5, 5);

        $climbing = match ($role) {
            'hilly' => $factory->state(['distance_meters' => $distanceMeters])->hilly(),
            'steep' => $factory->state(['distance_meters' => $distanceMeters])->steep(),
            default => $factory->state(['distance_meters' => $distanceMeters])->flat(),
        };

        $ascentMeters = (int) $climbing->make()->total_ascent_meters;

        $flatEquivalentPace = $paceAtEasyHeartRate * self::EASY_HEART_RATE / $heartRate + fake()->numberBetween(-3, 3);
        $pace = (int) round($flatEquivalentPace * ($distanceMeters + 8 * $ascentMeters) / $distanceMeters);

        $attributes = [
            'distance_meters' => $distanceMeters,
            'moving_time_seconds' => (int) round($distanceMeters / 1_000 * $pace),
            'average_pace_seconds_per_km' => $pace,
            'total_ascent_meters' => $ascentMeters,
        ];

        if (! $dropsHeartRate) {
            $attributes['average_heart_rate'] = $heartRate;
        }

        return $attributes;
    }
}
