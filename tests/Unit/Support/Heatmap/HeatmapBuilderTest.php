<?php

namespace Tests\Unit\Support\Heatmap;

use App\Data\Heatmap\DayRun;
use App\Enums\DayCellState;
use App\Support\Heatmap\BuildWeeklyTrend;
use App\Support\Heatmap\CalibrateIntensityScale;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class HeatmapBuilderTest extends TestCase
{
    private function builder(): HeatmapBuilder
    {
        return new HeatmapBuilder(new CalibrateIntensityScale, new BuildWeeklyTrend);
    }

    public function test_the_grid_has_16_rows_of_7_cells_oldest_week_first(): void
    {
        $today = CarbonImmutable::parse('2026-09-05'); // Saturday
        $window = HeatmapWindow::current($today);

        $grid = $this->builder()($window, [], $today);

        $this->assertCount(16, $grid->weeks);

        foreach ($grid->weeks as $week) {
            $this->assertCount(7, $week->days);
        }

        $this->assertTrue($grid->weeks[0]->weekStart->equalTo($window->startsOn));
        $this->assertTrue($grid->weeks[15]->weekStart->equalTo($today->startOfWeek()));
    }

    public function test_columns_are_iso8601_monday_through_sunday(): void
    {
        $today = CarbonImmutable::parse('2026-09-05');
        $window = HeatmapWindow::current($today);

        $grid = $this->builder()($window, [], $today);

        foreach ($grid->weeks as $week) {
            foreach ($week->days as $position => $day) {
                $this->assertSame($position + 1, $day->date->dayOfWeekIso);
            }
        }
    }

    public function test_a_day_after_today_in_the_current_week_is_future(): void
    {
        $today = CarbonImmutable::parse('2026-09-05'); // Saturday
        $window = HeatmapWindow::current($today);

        $grid = $this->builder()($window, [], $today);
        $currentWeek = $grid->weeks[15];

        $this->assertSame(DayCellState::Future, $currentWeek->days[6]->state); // Sunday, tomorrow
    }

    public function test_a_day_at_or_before_today_with_no_run_is_rest_not_future(): void
    {
        $today = CarbonImmutable::parse('2026-09-05');
        $window = HeatmapWindow::current($today);

        $grid = $this->builder()($window, [], $today);
        $currentWeek = $grid->weeks[15];

        $this->assertSame(DayCellState::Rest, $currentWeek->days[0]->state); // Monday, elapsed
        $this->assertNotSame(DayCellState::Future, $currentWeek->days[0]->state);
    }

    public function test_the_current_weeks_total_covers_only_elapsed_days_and_is_partial(): void
    {
        $today = CarbonImmutable::parse('2026-09-05'); // Saturday
        $window = HeatmapWindow::current($today);
        $dailyMeters = [
            $today->toDateString() => 8_200,
        ];

        $grid = $this->builder()($window, $dailyMeters, $today);
        $currentWeek = $grid->weeks[15];

        $this->assertTrue($currentWeek->isPartial);
        $this->assertTrue($currentWeek->isCurrent);
        $this->assertSame(8_200, $currentWeek->totalMeters);
    }

    public function test_a_completed_week_is_not_partial_even_with_rest_days(): void
    {
        $today = CarbonImmutable::parse('2026-09-05');
        $window = HeatmapWindow::current($today);

        $grid = $this->builder()($window, [], $today);
        $completedWeek = $grid->weeks[0];

        $this->assertFalse($completedWeek->isPartial);
        $this->assertFalse($completedWeek->isCurrent);
    }

    public function test_a_combined_distance_from_multiple_runs_renders_as_a_single_cell(): void
    {
        $today = CarbonImmutable::parse('2026-09-05');
        $window = HeatmapWindow::current($today);
        $dailyMeters = [
            $today->toDateString() => 5_000 + 3_200,
        ];

        $grid = $this->builder()($window, $dailyMeters, $today);
        $currentWeek = $grid->weeks[15];

        $this->assertSame(DayCellState::Ran, $currentWeek->days[5]->state); // Saturday
        $this->assertSame('8.2', $currentWeek->days[5]->kilometres());
    }

    public function test_a_window_spanning_a_month_boundary_keeps_every_date_in_its_correct_weekday_column(): void
    {
        $startsOn = CarbonImmutable::parse('2026-01-26')->startOfWeek(); // spans Jan -> Feb
        $endsOn = $startsOn->addDays(111);
        $window = new HeatmapWindow($startsOn, $endsOn);

        $grid = $this->builder()($window, [], $endsOn);

        foreach ($grid->weeks as $week) {
            foreach ($week->days as $position => $day) {
                $this->assertSame($position + 1, $day->date->dayOfWeekIso);
            }
        }
    }

    public function test_a_window_spanning_a_year_boundary_keeps_every_date_in_its_correct_weekday_column(): void
    {
        $startsOn = CarbonImmutable::parse('2025-11-24')->startOfWeek(); // spans into 2026
        $endsOn = $startsOn->addDays(111);
        $window = new HeatmapWindow($startsOn, $endsOn);
        $today = CarbonImmutable::parse('2026-01-15'); // January $today

        $grid = $this->builder()($window, [], $today);

        foreach ($grid->weeks as $week) {
            foreach ($week->days as $position => $day) {
                $this->assertSame($position + 1, $day->date->dayOfWeekIso);
            }
        }
    }

    public function test_ran_cells_carry_the_level_the_grids_own_scale_assigns(): void
    {
        $today = CarbonImmutable::parse('2026-09-05');
        $window = HeatmapWindow::current($today);
        $dailyMeters = [
            $today->toDateString() => 8_200,
        ];

        $grid = $this->builder()($window, $dailyMeters, $today);
        $currentWeek = $grid->weeks[15];
        $ranDay = $currentWeek->days[5]; // Saturday, the only ran day

        $this->assertSame(DayCellState::Ran, $ranDay->state);
        $this->assertSame($grid->scale->levelFor(8_200), $ranDay->level);
    }

    public function test_future_cells_never_carry_a_heat_level(): void
    {
        $today = CarbonImmutable::parse('2026-09-05'); // Saturday
        $window = HeatmapWindow::current($today);

        $grid = $this->builder()($window, [], $today);
        $currentWeek = $grid->weeks[15];
        $futureDay = $currentWeek->days[6]; // Sunday, tomorrow

        $this->assertSame(DayCellState::Future, $futureDay->state);
        $this->assertSame(0, $futureDay->level);
        $this->assertSame('future', $futureDay->modifier());
    }

    public function test_a_window_with_no_runs_at_all_yields_112_rest_or_future_cells_and_16_zero_totals(): void
    {
        $today = CarbonImmutable::parse('2026-09-05');
        $window = HeatmapWindow::current($today);

        $grid = $this->builder()($window, [], $today);

        $cellCount = 0;

        foreach ($grid->weeks as $week) {
            $this->assertSame(0, $week->totalMeters);

            foreach ($week->days as $day) {
                $this->assertContains($day->state, [DayCellState::Rest, DayCellState::Future]);
                $cellCount++;
            }
        }

        $this->assertSame(112, $cellCount);
    }

    public function test_runs_are_attached_only_to_ran_days_in_the_order_given(): void
    {
        $today = CarbonImmutable::parse('2026-09-05'); // Saturday
        $window = HeatmapWindow::current($today);
        $ranDay = $today->subDays(2)->toDateString();
        $first = new DayRun('Morning run', 5_000, 1_650, 330, 20, 140, null);
        $second = new DayRun('Evening run', 3_000, 1_100, 366, null, null, null);

        $grid = $this->builder()(
            $window,
            [$ranDay => 8_000],
            $today,
            [
                $ranDay => [$first, $second],
                $today->subDays(3)->toDateString() => [$first], // a run the grid counted as 0 m: a rest day
                $today->addDay()->toDateString() => [$first],   // a future day
            ],
        );

        $cells = collect($grid->weeks)->flatMap(fn ($week) => $week->days);

        $this->assertSame([$first, $second], $cells->first(fn ($cell) => $cell->date->toDateString() === $ranDay)->runs);
        $this->assertSame(
            1,
            $cells->filter(fn ($cell) => $cell->runs !== [])->count(),
            'Only the ran day carries runs.',
        );
        $this->assertTrue($cells->first(fn ($cell) => $cell->date->toDateString() === $ranDay)->hasDetail());
    }

    public function test_a_grid_built_without_runs_has_no_detail_anywhere(): void
    {
        $today = CarbonImmutable::parse('2026-09-05');

        $grid = $this->builder()(HeatmapWindow::current($today), [$today->subDay()->toDateString() => 8_000], $today);

        $this->assertFalse(collect($grid->weeks)->flatMap(fn ($week) => $week->days)->contains(fn ($cell) => $cell->hasDetail()));
    }
}
