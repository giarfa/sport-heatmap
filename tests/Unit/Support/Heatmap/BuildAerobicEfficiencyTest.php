<?php

namespace Tests\Unit\Support\Heatmap;

use App\Data\Heatmap\AerobicEfficiency;
use App\Data\Heatmap\DayCell;
use App\Data\Heatmap\EfficiencyMeasurement;
use App\Data\Heatmap\MeasuredRun;
use App\Data\Heatmap\TrendTick;
use App\Data\Heatmap\WeekRow;
use App\Enums\AerobicEfficiencyState;
use App\Enums\DayCellState;
use App\Enums\EfficiencyRunKind;
use App\Support\Heatmap\BuildAerobicEfficiency;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BuildAerobicEfficiencyTest extends TestCase
{
    private const WINDOW_LABEL = '29 Jun – 18 Oct 2026';

    public function test_the_headline_and_note_read_the_first_and_latest_points(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([0 => 414.0, 15 => 389.0])));

        $this->assertSame(AerobicEfficiencyState::Measured, $card->state);
        $this->assertTrue($card->hasHeadline());
        $this->assertSame('6:29', $card->headlinePace);
        $this->assertSame('at 148 bpm', $card->headlineAt);
        $this->assertSame("25 s/km faster than 16 weeks ago (6:54 /km) · 148 bpm is this window's median heart rate", $card->note);
    }

    public function test_a_slower_latest_point_says_slower(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([0 => 389.0, 15 => 414.0])));

        $this->assertSame("25 s/km slower than 16 weeks ago (6:29 /km) · 148 bpm is this window's median heart rate", $card->note);
    }

    public function test_equal_rounded_paces_read_the_same_pace(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([0 => 389.4, 15 => 388.6])));

        $this->assertSame("same pace as 16 weeks ago · 148 bpm is this window's median heart rate", $card->note);
    }

    public function test_the_week_count_runs_from_the_first_point_to_the_latest(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([3 => 414.0, 15 => 389.0])));

        $this->assertStringStartsWith('25 s/km faster than 13 weeks ago', $card->note);
    }

    public function test_the_change_is_computed_on_the_rounded_paces(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([0 => 413.6, 15 => 388.4])));

        $this->assertSame('6:28', $card->headlinePace);
        $this->assertStringStartsWith('26 s/km faster than 16 weeks ago (6:54 /km)', $card->note);
    }

    public function test_a_single_point_prints_the_headline_without_a_comparison(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([15 => 389.0])));

        $this->assertSame('6:29', $card->headlinePace);
        $this->assertSame("148 bpm is this window's median heart rate", $card->note);
    }

    public function test_a_window_with_no_stored_run_is_the_empty_state(): void
    {
        $card = $this->build($this->measurement(hasRuns: false, qualifyingCount: 0, referenceHeartRate: null));

        $this->assertSame(AerobicEfficiencyState::Empty, $card->state);
        $this->assertSame('No runs in this window, so there is nothing to chart.', $card->message);
        $this->assertFalse($card->hasChart());
        $this->assertFalse($card->hasHeadline());
    }

    public function test_fewer_than_eight_qualifying_runs_states_the_count_and_draws_nothing(): void
    {
        $card = $this->build($this->measurement(qualifyingCount: 6, linePoints: $this->points([15 => 389.0])));

        $this->assertSame(AerobicEfficiencyState::NotEnoughRuns, $card->state);
        $this->assertSame(
            'Not enough runs to measure efficiency: 6 in this window, 8 needed. A run counts when it is at least 3 km with pace and heart rate recorded.',
            $card->message,
        );
        $this->assertFalse($card->hasChart());
        $this->assertSame('', $card->headlinePace);
        $this->assertSame([], $card->dots);
        $this->assertSame([], $card->weeks);
    }

    public function test_eight_qualifying_runs_are_enough(): void
    {
        $card = $this->build($this->measurement(qualifyingCount: 8, linePoints: $this->points([15 => 389.0])));

        $this->assertSame(AerobicEfficiencyState::Measured, $card->state);
    }

    public function test_no_median_keeps_the_dots_and_the_rows_and_replaces_the_headline(): void
    {
        $card = $this->build($this->measurement(runs: [$this->dot(5, 2, 390.0)], runsPerWeek: $this->runsPerWeek([5 => 1])));

        $this->assertSame(AerobicEfficiencyState::NoMedian, $card->state);
        $this->assertSame('No 4-week median yet: no 4 weeks hold 3 usable runs.', $card->message);
        $this->assertTrue($card->hasChart());
        $this->assertFalse($card->hasHeadline());
        $this->assertFalse($card->hasMedianLine());
        $this->assertSame('', $card->headlinePace);
        $this->assertCount(1, $card->dots);
        $this->assertCount(16, $card->weeks);
        $this->assertSame(1, $card->weeks[5]->runs);
    }

    public function test_the_axis_steps_every_15_seconds_over_the_dots_and_last_year(): void
    {
        $card = $this->build($this->measurement(
            runs: [$this->dot(3, 0, 372.0)],
            linePoints: $this->points([0 => 410.0, 15 => 400.0]),
            lastYearPoints: $this->points([4 => 455.0]),
            hasLastYear: true,
        ));

        $this->assertSame(
            ['6:00 /km', '6:15', '6:30', '6:45', '7:00', '7:15', '7:30', '7:45'],
            array_map(fn (TrendTick $tick): string => $tick->label, $card->ticks),
        );
        $this->assertSame('0.00', $card->ticks[0]->y);
        $this->assertSame('100.00', $card->ticks[0]->bottomPercent);
        $this->assertSame('100.00', $card->ticks[7]->y);
        $this->assertSame('0.00', $card->ticks[7]->bottomPercent);
    }

    public function test_the_axis_ignores_last_year_when_it_is_absent(): void
    {
        $card = $this->build($this->measurement(
            linePoints: $this->points([0 => 410.0, 15 => 400.0]),
            lastYearPoints: $this->points([4 => 600.0]),
            hasLastYear: false,
        ));

        $this->assertSame('7:00', $card->ticks[array_key_last($card->ticks)]->label);
    }

    public function test_equal_extremes_widen_the_axis_by_one_step(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([15 => 390.0])));

        $this->assertSame(['6:30 /km', '6:45'], array_map(fn (TrendTick $tick): string => $tick->label, $card->ticks));
    }

    public function test_a_wednesday_run_in_slot_five_is_positioned_by_its_weekday_and_pace(): void
    {
        $card = $this->build($this->measurement(
            runs: [$this->dot(5, 2, 390.0), $this->dot(5, 3, 375.0)],
            linePoints: $this->points([0 => 375.0, 15 => 405.0]),
        ));

        $this->assertSame('33.65', $card->dots[0]->left);
        $this->assertSame('50.00', $card->dots[0]->top);
        $this->assertSame('0.00', $card->dots[1]->top);
        $this->assertLessThan((float) $card->dots[0]->top, (float) $card->dots[1]->top);
    }

    public function test_the_lines_break_at_a_missing_point_and_a_lone_point_becomes_a_tick(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([0 => 390.0, 1 => 390.0, 3 => 390.0])));

        $this->assertSame(['5,0.00 15,0.00', '33,0.00 37,0.00'], $card->medianSegments);
    }

    public function test_last_year_is_drawn_and_listed_when_present(): void
    {
        $card = $this->build($this->measurement(
            linePoints: $this->points([14 => 390.0, 15 => 395.0]),
            lastYearPoints: $this->points([14 => 420.0, 15 => 425.0]),
            hasLastYear: true,
        ));

        $this->assertTrue($card->hasLastYear);
        $this->assertCount(1, $card->lastYearSegments);
        $this->assertSame('7:00 /km', $card->weeks[14]->lastYear);
        $this->assertNull($card->weeks[13]->lastYear);
    }

    public function test_last_year_is_absent_without_a_stored_run(): void
    {
        $card = $this->build($this->measurement(
            linePoints: $this->points([15 => 390.0]),
            lastYearPoints: $this->points([15 => 420.0]),
            hasLastYear: false,
        ));

        $this->assertFalse($card->hasLastYear);
        $this->assertSame([], $card->lastYearSegments);
        $this->assertNull($card->weeks[15]->lastYear);
    }

    public function test_the_key_flags_follow_the_kinds_of_run_drawn(): void
    {
        $flatOnly = $this->build($this->measurement(runs: [$this->dot(1, 0, 390.0)], linePoints: $this->points([15 => 390.0])));
        $withHilly = $this->build($this->measurement(runs: [$this->dot(1, 0, 390.0, EfficiencyRunKind::Hilly)], linePoints: $this->points([15 => 390.0])));
        $withSteep = $this->build($this->measurement(runs: [$this->dot(1, 0, 390.0, EfficiencyRunKind::Steep)], linePoints: $this->points([15 => 390.0])));

        $this->assertFalse($flatOnly->hasHilly);
        $this->assertFalse($flatOnly->hasSteep);
        $this->assertTrue($withHilly->hasHilly);
        $this->assertFalse($withHilly->hasSteep);
        $this->assertTrue($withSteep->hasSteep);
        $this->assertSame(EfficiencyRunKind::Steep, $withSteep->dots[0]->kind);
    }

    public function test_the_title_caption_x_labels_and_week_rows_name_the_window(): void
    {
        $card = $this->build($this->measurement(
            runsPerWeek: $this->runsPerWeek([0 => 3]),
            linePoints: $this->points([0 => 414.0, 15 => 389.0]),
        ));

        $this->assertSame(
            'Pace at 148 bpm per run, 29 Jun – 18 Oct 2026, faster at the top; 4-week median 6:54 to 6:29 per km. Weekly figures below.',
            $card->title,
        );
        $this->assertSame('Pace at 148 bpm by week, 29 Jun – 18 Oct 2026', $card->captionLabel);
        $this->assertSame(['29 Jun', '27 Jul', '24 Aug', '21 Sep'], $card->xLabels);
        $this->assertSame('29 Jun – 5 Jul', $card->weeks[0]->rangeLabel);
        $this->assertSame(3, $card->weeks[0]->runs);
        $this->assertSame('6:54 /km', $card->weeks[0]->median);
        $this->assertNull($card->weeks[1]->median);
    }

    public function test_a_missing_point_is_never_printed_as_zero(): void
    {
        $card = $this->build($this->measurement(linePoints: $this->points([15 => 389.0])));

        foreach ($card->weeks as $week) {
            $this->assertNotSame('0:00 /km', $week->median);
            $this->assertNotSame('0:00 /km', $week->lastYear);
        }
    }

    public function test_the_copy_never_praises_warns_or_advises(): void
    {
        $cards = [
            $this->build($this->measurement(linePoints: $this->points([0 => 414.0, 15 => 389.0]))),
            $this->build($this->measurement(linePoints: $this->points([0 => 389.0, 15 => 414.0]))),
            $this->build($this->measurement(linePoints: $this->points([0 => 389.0, 15 => 389.0]))),
            $this->build($this->measurement(qualifyingCount: 6)),
            $this->build($this->measurement(hasRuns: false, qualifyingCount: 0)),
            $this->build($this->measurement()),
        ];

        foreach ($cards as $card) {
            $copy = implode(' ', [$card->message, $card->note, $card->title, $card->captionLabel]);

            $this->assertDoesNotMatchRegularExpression('/\b(good|great|well done|warning|should|try)\b/i', $copy);
        }
    }

    /**
     * @param  array<int, float|null>  $linePoints
     * @param  array<int, float|null>  $lastYearPoints
     * @param  array<int, MeasuredRun>  $runs
     * @param  array<int, int>|null  $runsPerWeek
     */
    private function measurement(
        array $linePoints = [],
        array $lastYearPoints = [],
        array $runs = [],
        ?array $runsPerWeek = null,
        ?int $referenceHeartRate = 148,
        int $qualifyingCount = 20,
        bool $hasRuns = true,
        bool $hasLastYear = false,
    ): EfficiencyMeasurement {
        return new EfficiencyMeasurement(
            referenceHeartRate: $referenceHeartRate,
            qualifyingCount: $qualifyingCount,
            hasRuns: $hasRuns,
            runs: $runs,
            linePoints: $linePoints === [] ? $this->points([]) : $linePoints,
            lastYearPoints: $lastYearPoints === [] ? $this->points([]) : $lastYearPoints,
            runsPerWeek: $runsPerWeek ?? $this->runsPerWeek([]),
            hasLastYear: $hasLastYear,
        );
    }

    /**
     * @param  array<int, float>  $byPosition
     * @return array<int, float|null>
     */
    private function points(array $byPosition): array
    {
        $points = array_fill(0, 16, null);

        foreach ($byPosition as $position => $pace) {
            $points[$position] = $pace;
        }

        return $points;
    }

    /**
     * @param  array<int, int>  $byPosition
     * @return array<int, int>
     */
    private function runsPerWeek(array $byPosition): array
    {
        $counts = array_fill(0, 16, 0);

        foreach ($byPosition as $position => $count) {
            $counts[$position] = $count;
        }

        return $counts;
    }

    private function dot(int $week, int $weekday, float $pace, EfficiencyRunKind $kind = EfficiencyRunKind::Flat): MeasuredRun
    {
        return new MeasuredRun($this->window()->startsOn->addWeeks($week)->addDays($weekday), $weekday, $week, $kind, $pace);
    }

    /**
     * The window of Tuesday 13 Oct 2026: Monday 29 Jun to Sunday 18 Oct 2026.
     */
    private function window(): HeatmapWindow
    {
        return HeatmapWindow::current(CarbonImmutable::parse('2026-10-13'));
    }

    private function build(EfficiencyMeasurement $measurement): AerobicEfficiency
    {
        $weeks = [];

        foreach (range(0, 15) as $position) {
            $weekStart = $this->window()->startsOn->addWeeks($position);
            $days = array_map(
                fn (int $day): DayCell => new DayCell($weekStart->addDays($day), 0, DayCellState::Rest, 0),
                range(0, 6),
            );
            $weeks[] = new WeekRow($weekStart, $days, 0, false, false);
        }

        return (new BuildAerobicEfficiency)($measurement, $weeks, self::WINDOW_LABEL);
    }
}
