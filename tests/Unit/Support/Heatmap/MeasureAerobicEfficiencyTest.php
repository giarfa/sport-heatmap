<?php

namespace Tests\Unit\Support\Heatmap;

use App\Data\Heatmap\EfficiencyMeasurement;
use App\Data\Heatmap\EfficiencyRun;
use App\Data\Heatmap\EfficiencySample;
use App\Enums\EfficiencyRunKind;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Heatmap\MeasureAerobicEfficiency;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeasureAerobicEfficiencyTest extends TestCase
{
    public function test_the_reference_heart_rate_is_the_rounded_median_of_the_windows_qualifying_runs(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 10, heartRate: 146),
            $this->windowRun(week: 11, heartRate: 148),
            $this->windowRun(week: 12, heartRate: 150),
        ]);

        $this->assertSame(148, $measurement->referenceHeartRate);
    }

    public function test_a_half_beat_median_rounds_up(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 10, heartRate: 147),
            $this->windowRun(week: 11, heartRate: 148),
        ]);

        $this->assertSame(148, $measurement->referenceHeartRate);
    }

    public function test_lead_in_and_last_year_runs_do_not_move_the_reference(): void
    {
        $measurement = $this->measure(
            [
                $this->windowRun(week: -3, heartRate: 120),
                $this->windowRun(week: -2, heartRate: 120),
                $this->windowRun(week: -1, heartRate: 120),
                $this->windowRun(week: 10, heartRate: 148),
                $this->windowRun(week: 11, heartRate: 150),
                $this->windowRun(week: 12, heartRate: 146),
            ],
            [
                $this->lastYearRun(week: 10, heartRate: 120),
                $this->lastYearRun(week: 11, heartRate: 120),
            ],
        );

        $this->assertSame(148, $measurement->referenceHeartRate);
        $this->assertSame(3, $measurement->qualifyingCount);
    }

    public function test_a_window_with_no_qualifying_run_has_no_reference_and_no_points(): void
    {
        $measurement = $this->measure([$this->windowRun(week: 15, pace: null, heartRate: null)]);

        $this->assertNull($measurement->referenceHeartRate);
        $this->assertSame(0, $measurement->qualifyingCount);
        $this->assertTrue($measurement->hasRuns);
        $this->assertSame([], $measurement->runs);
        $this->assertSame(array_fill(0, 16, null), $measurement->linePoints);
    }

    public function test_a_window_with_no_stored_run_has_no_runs(): void
    {
        $this->assertFalse($this->measure([])->hasRuns);
    }

    public function test_fewer_than_eight_qualifying_runs_reports_the_number_found(): void
    {
        $measurement = $this->measure($this->flatRuns(weeks: [9, 10, 11, 12, 13, 14]));

        $this->assertSame(6, $measurement->qualifyingCount);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function excludedRuns(): array
    {
        return [
            'under 3 km' => [['distance' => 2_900], false],
            'no pace' => [['pace' => null], false],
            'no heart rate' => [['heartRate' => null], false],
            'more than 10 bpm above the reference' => [['heartRate' => 159], false],
            'exactly 10 bpm above the reference' => [['heartRate' => 158], true],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('excludedRuns')]
    public function test_a_run_that_fails_a_gate_is_never_drawn_and_never_moves_the_line(array $overrides, bool $isDrawn): void
    {
        $base = [
            $this->windowRun(week: 5, pace: 330),
            $this->windowRun(week: 5, weekday: 1, pace: 340),
            $this->windowRun(week: 5, weekday: 2, pace: 350),
        ];
        $extra = [
            $this->windowRun(...[...['week' => 5, 'weekday' => 3, 'pace' => 100], ...$overrides]),
            $this->windowRun(...[...['week' => 5, 'weekday' => 4, 'pace' => 100], ...$overrides]),
        ];

        $measurement = $this->measure([...$base, ...$extra]);

        $this->assertSame($isDrawn ? 5 : 3, count($measurement->runs));

        if (! $isDrawn) {
            $this->assertSame(340.0, $measurement->linePoints[5]);
        }
    }

    public function test_a_run_without_pace_or_heart_rate_is_never_drawn_as_zero(): void
    {
        $measurement = $this->measure([
            ...$this->flatRuns(weeks: [8, 9, 10, 11, 12, 13, 14, 15]),
            $this->windowRun(week: 15, weekday: 1, pace: null),
            $this->windowRun(week: 15, weekday: 2, heartRate: null),
        ]);

        $this->assertCount(8, $measurement->runs);

        foreach ($measurement->runs as $run) {
            $this->assertGreaterThan(0.0, $run->paceAtReference);
        }
    }

    public function test_a_stored_zero_pace_or_heart_rate_is_not_a_recorded_value(): void
    {
        $measurement = $this->measure([
            ...$this->flatRuns(weeks: [8, 9, 10, 11, 12, 13, 14, 15]),
            $this->windowRun(week: 15, weekday: 1, pace: 0),
            $this->windowRun(week: 15, weekday: 2, heartRate: 0),
        ]);

        $this->assertSame(8, $measurement->qualifyingCount);
        $this->assertCount(8, $measurement->runs);
    }

    public function test_a_run_that_climbs_is_converted_to_its_flat_equivalent_distance(): void
    {
        $measurement = $this->measure([$this->windowRun(week: 15, distance: 5_000, pace: 360, heartRate: 150, ascent: 40)]);

        $this->assertSame(EfficiencyRunKind::Hilly, $measurement->runs[0]->kind);
        $this->assertEqualsWithDelta(360 * 5_000 / 5_320, $measurement->runs[0]->paceAtReference, 0.001);
    }

    public function test_a_run_at_a_heart_rate_other_than_the_reference_is_restated_at_the_reference(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 10, heartRate: 150),
            $this->windowRun(week: 11, heartRate: 150),
            $this->windowRun(week: 12, heartRate: 140, pace: 340),
        ]);

        $this->assertSame(150, $measurement->referenceHeartRate);
        $this->assertEqualsWithDelta(340 * 140 / 150, $measurement->runs[2]->paceAtReference, 0.001);
    }

    public function test_the_kind_follows_the_climb_at_each_boundary(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 14),
            $this->windowRun(week: 15, weekday: 0, distance: 10_000, ascent: 49),
            $this->windowRun(week: 15, weekday: 1, distance: 10_000, ascent: 50),
            $this->windowRun(week: 15, weekday: 2, distance: 10_000, ascent: 100),
            $this->windowRun(week: 15, weekday: 3, distance: 10_000, ascent: 101),
            $this->windowRun(week: 15, weekday: 4, distance: 3_300, ascent: 33),
        ]);

        $kinds = [];

        foreach ($measurement->runs as $run) {
            if ($run->weekPosition === 15) {
                $kinds[$run->weekday] = $run->kind;
            }
        }

        $this->assertSame([
            0 => EfficiencyRunKind::Flat,
            1 => EfficiencyRunKind::Hilly,
            2 => EfficiencyRunKind::Hilly,
            3 => EfficiencyRunKind::Steep,
            4 => EfficiencyRunKind::Hilly,
        ], $kinds);
    }

    public function test_a_steep_run_is_not_qualifying_and_never_enters_a_median(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 5, pace: 330),
            $this->windowRun(week: 5, weekday: 1, pace: 340),
            $this->windowRun(week: 5, weekday: 2, pace: 350),
            $this->windowRun(week: 5, weekday: 3, pace: 200, distance: 10_000, ascent: 150),
        ]);

        $this->assertSame(3, $measurement->qualifyingCount);
        $this->assertCount(4, $measurement->runs);
        $this->assertSame(340.0, $measurement->linePoints[5]);
    }

    public function test_the_first_weeks_pool_the_lead_in_weeks(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: -3, pace: 320),
            $this->windowRun(week: -2, pace: 330),
            $this->windowRun(week: -1, pace: 340),
            $this->windowRun(week: 0, pace: 350),
        ]);

        $this->assertSame(335.0, $measurement->linePoints[0]);
        $this->assertCount(1, $measurement->runs);
    }

    public function test_a_week_pools_only_the_week_and_the_three_before_it(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 1, pace: 330),
            $this->windowRun(week: 1, weekday: 1, pace: 330),
            $this->windowRun(week: 1, weekday: 2, pace: 330),
        ]);

        $this->assertNotNull($measurement->linePoints[4]);
        $this->assertNull($measurement->linePoints[5]);
    }

    public function test_flat_runs_alone_make_the_point_when_four_or_more_are_pooled(): void
    {
        $measurement = $this->measure([
            ...$this->flatRuns(weeks: [5, 5, 5, 5], pace: 330),
            $this->windowRun(week: 5, weekday: 5, pace: 400, distance: 10_000, ascent: 70),
            $this->windowRun(week: 5, weekday: 6, pace: 400, distance: 10_000, ascent: 70),
        ]);

        $this->assertSame(330.0, $measurement->linePoints[5]);
    }

    public function test_hilly_runs_join_when_fewer_than_four_flat_runs_are_pooled(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 5, pace: 320),
            $this->windowRun(week: 5, weekday: 1, pace: 330),
            $this->windowRun(week: 5, weekday: 2, pace: 340, distance: 10_000, ascent: 70),
        ]);

        $this->assertEqualsWithDelta(340 * 10_000 / 10_560, $measurement->runs[2]->paceAtReference, 0.001);
        $this->assertSame($measurement->runs[2]->paceAtReference, $measurement->linePoints[5]);
    }

    public function test_fewer_than_three_usable_runs_leave_no_point(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 5, pace: 330),
            $this->windowRun(week: 5, weekday: 1, pace: 340),
        ]);

        $this->assertNull($measurement->linePoints[5]);
    }

    public function test_an_even_count_takes_the_mean_of_the_two_middle_values(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 5, pace: 320),
            $this->windowRun(week: 5, weekday: 1, pace: 330),
            $this->windowRun(week: 5, weekday: 2, pace: 340),
            $this->windowRun(week: 5, weekday: 3, pace: 350),
        ]);

        $this->assertSame(335.0, $measurement->linePoints[5]);
    }

    public function test_a_gap_leaves_a_null_between_two_points_and_nothing_is_interpolated(): void
    {
        $measurement = $this->measure([
            ...$this->flatRuns(weeks: [1, 1, 1]),
            ...$this->flatRuns(weeks: [6, 6, 6]),
        ]);

        $this->assertNotNull($measurement->linePoints[4]);
        $this->assertNull($measurement->linePoints[5]);
        $this->assertNotNull($measurement->linePoints[6]);
    }

    public function test_last_year_is_read_at_the_windows_reference_heart_rate(): void
    {
        $measurement = $this->measure(
            [
                $this->windowRun(week: 10, heartRate: 148),
                $this->windowRun(week: 11, heartRate: 148),
                $this->windowRun(week: 12, heartRate: 148),
            ],
            [
                $this->lastYearRun(week: 15, pace: 350, heartRate: 140),
                $this->lastYearRun(week: 15, weekday: 1, pace: 350, heartRate: 140),
                $this->lastYearRun(week: 15, weekday: 2, pace: 350, heartRate: 140),
            ],
        );

        $this->assertTrue($measurement->hasLastYear);
        $this->assertEqualsWithDelta(350 * 140 / 148, $measurement->lastYearPoints[15], 0.001);
        $this->assertNull($measurement->lastYearPoints[0]);
    }

    public function test_last_year_guards_outliers_against_the_windows_reference(): void
    {
        $measurement = $this->measure(
            $this->flatRuns(weeks: [8, 9, 10, 11, 12, 13, 14, 15]),
            [
                $this->lastYearRun(week: 5, heartRate: 148),
                $this->lastYearRun(week: 5, weekday: 1, heartRate: 148),
                $this->lastYearRun(week: 5, weekday: 2, heartRate: 159),
            ],
        );

        $this->assertNull($measurement->lastYearPoints[5]);
    }

    public function test_last_year_is_absent_when_no_run_is_stored_in_those_weeks(): void
    {
        $measurement = $this->measure($this->flatRuns(weeks: [13, 14, 15]));

        $this->assertFalse($measurement->hasLastYear);
        $this->assertSame(array_fill(0, 16, null), $measurement->lastYearPoints);
    }

    public function test_last_year_is_present_with_runs_that_make_no_point(): void
    {
        $measurement = $this->measure(
            $this->flatRuns(weeks: [13, 14, 15]),
            [$this->lastYearRun(week: 7, pace: null, heartRate: null)],
        );

        $this->assertTrue($measurement->hasLastYear);
        $this->assertSame(array_fill(0, 16, null), $measurement->lastYearPoints);
    }

    public function test_a_last_year_lead_in_run_alone_is_not_last_year(): void
    {
        $measurement = $this->measure(
            $this->flatRuns(weeks: [13, 14, 15]),
            [$this->lastYearRun(week: -1)],
        );

        $this->assertFalse($measurement->hasLastYear);
    }

    public function test_runs_per_week_counts_flat_hilly_and_steep_dots(): void
    {
        $measurement = $this->measure([
            $this->windowRun(week: 14),
            $this->windowRun(week: 15),
            $this->windowRun(week: 15, weekday: 1, distance: 10_000, ascent: 70),
            $this->windowRun(week: 15, weekday: 2, distance: 10_000, ascent: 150),
            $this->windowRun(week: 15, weekday: 3, pace: null),
        ]);

        $this->assertSame(1, $measurement->runsPerWeek[14]);
        $this->assertSame(3, $measurement->runsPerWeek[15]);
        $this->assertSame(0, $measurement->runsPerWeek[0]);
        $this->assertCount(16, $measurement->runsPerWeek);
    }

    public function test_every_drawn_run_carries_its_week_and_weekday(): void
    {
        $measurement = $this->measure([$this->windowRun(week: 5, weekday: 2)]);

        $this->assertSame(5, $measurement->runs[0]->weekPosition);
        $this->assertSame(2, $measurement->runs[0]->weekday);
    }

    /**
     * The window of Tuesday 13 Oct 2026: Monday 29 Jun to Sunday 18 Oct 2026.
     */
    private function window(): HeatmapWindow
    {
        return HeatmapWindow::current(CarbonImmutable::parse('2026-10-13'));
    }

    /**
     * @param  array<int, EfficiencyRun>  $runs
     * @param  array<int, EfficiencyRun>  $lastYearRuns
     */
    private function measure(array $runs, array $lastYearRuns = []): EfficiencyMeasurement
    {
        return (new MeasureAerobicEfficiency)($this->window(), new EfficiencySample($runs, $lastYearRuns));
    }

    /**
     * A run in a window week (0 to 15, negative for the lead-in), on a weekday (Monday = 0).
     * Defaults describe a flat 8 km run at 5:30 /km and 148 bpm.
     */
    private function windowRun(
        int $week,
        int $weekday = 0,
        int $distance = 8_000,
        ?int $pace = 330,
        ?int $heartRate = 148,
        ?int $ascent = 0,
    ): EfficiencyRun {
        return new EfficiencyRun(
            $this->window()->startsOn->addWeeks($week)->addDays($weekday),
            $distance,
            $pace,
            $heartRate,
            $ascent,
        );
    }

    private function lastYearRun(
        int $week,
        int $weekday = 0,
        int $distance = 8_000,
        ?int $pace = 330,
        ?int $heartRate = 148,
        ?int $ascent = 0,
    ): EfficiencyRun {
        return new EfficiencyRun(
            $this->window()->startsOn->subWeeks(52)->addWeeks($week)->addDays($weekday),
            $distance,
            $pace,
            $heartRate,
            $ascent,
        );
    }

    /**
     * One flat run per listed week; repeated weeks land on successive weekdays.
     *
     * @param  array<int, int>  $weeks
     * @return array<int, EfficiencyRun>
     */
    private function flatRuns(array $weeks, int $pace = 330): array
    {
        $perWeek = [];
        $runs = [];

        foreach ($weeks as $week) {
            $perWeek[$week] = ($perWeek[$week] ?? -1) + 1;
            $runs[] = $this->windowRun(week: $week, weekday: $perWeek[$week], pace: $pace);
        }

        return $runs;
    }
}
