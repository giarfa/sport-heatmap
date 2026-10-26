<?php

namespace Tests\Unit\Data\Heatmap;

use App\Data\Heatmap\DayCell;
use App\Data\Heatmap\DayRun;
use App\Enums\DayCellState;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DayCellTest extends TestCase
{
    public function test_kilometres_renders_to_one_decimal(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_200, DayCellState::Ran, 2);

        $this->assertSame('8.2', $cell->kilometres());
    }

    public function test_kilometres_rounds_down_just_below_the_half_step(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_249, DayCellState::Ran, 2);

        $this->assertSame('8.2', $cell->kilometres());
    }

    public function test_kilometres_rounds_up_at_the_half_step(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_250, DayCellState::Ran, 2);

        $this->assertSame('8.3', $cell->kilometres());
    }

    public function test_the_accessible_name_of_a_ran_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_200, DayCellState::Ran, 2);

        $this->assertSame('Tuesday 19 May 2026: 8.2 km', $cell->accessibleName());
    }

    public function test_the_accessible_name_of_a_rest_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-18'), 0, DayCellState::Rest, 0);

        $this->assertSame('Monday 18 May 2026: rest day, 0 km', $cell->accessibleName());
    }

    public function test_the_accessible_name_of_a_future_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-09-06'), 0, DayCellState::Future, 0);

        $this->assertSame('Sunday 6 Sep 2026: not yet', $cell->accessibleName());
    }

    public function test_the_pointer_title_of_a_ran_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_200, DayCellState::Ran, 2);

        $this->assertSame('Tuesday 19 May 2026 — 8.2 km', $cell->pointerTitle());
    }

    public function test_the_pointer_title_of_a_rest_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-18'), 0, DayCellState::Rest, 0);

        $this->assertSame('Monday 18 May 2026 — rest', $cell->pointerTitle());
    }

    public function test_a_future_day_carries_no_pointer_title(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-09-06'), 0, DayCellState::Future, 0);

        $this->assertNull($cell->pointerTitle());
    }

    public function test_the_modifier_of_a_rest_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-18'), 0, DayCellState::Rest, 0);

        $this->assertSame('rest', $cell->modifier());
    }

    public function test_the_modifier_of_a_ran_day_carries_its_level(): void
    {
        foreach ([1, 2, 3, 4] as $level) {
            $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_200, DayCellState::Ran, $level);

            $this->assertSame("l{$level}", $cell->modifier());
        }
    }

    public function test_a_future_day_never_carries_a_heat_level_modifier_even_if_constructed_with_one(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-09-06'), 0, DayCellState::Future, 4);

        $this->assertSame('future', $cell->modifier());
    }

    public function test_a_ran_day_with_runs_opens_a_detail_and_every_other_day_does_not(): void
    {
        $date = CarbonImmutable::parse('2026-10-03');

        $this->assertTrue((new DayCell($date, 13_600, DayCellState::Ran, 3, [$this->dayRun()]))->hasDetail());
        $this->assertFalse((new DayCell($date, 13_600, DayCellState::Ran, 3))->hasDetail());
        $this->assertFalse((new DayCell($date, 0, DayCellState::Rest, 0))->hasDetail());
        $this->assertFalse((new DayCell($date, 0, DayCellState::Future, 0, [$this->dayRun()]))->hasDetail());
    }

    public function test_the_detail_title_and_summary_count_the_runs(): void
    {
        $date = CarbonImmutable::parse('2026-10-03');

        $one = new DayCell($date, 8_200, DayCellState::Ran, 2, [$this->dayRun()]);
        $two = new DayCell($date, 13_600, DayCellState::Ran, 3, [$this->dayRun(), $this->dayRun()]);

        $this->assertSame('Sat 3 Oct 2026', $one->detailTitle());
        $this->assertSame('1 run · 8.2 km', $one->detailSummary());
        $this->assertSame('2 runs · 13.6 km', $two->detailSummary());
    }

    public function test_the_button_name_extends_the_accessible_name_with_the_run_count_and_the_action(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-10-03'), 13_600, DayCellState::Ran, 3, [$this->dayRun(), $this->dayRun()]);

        $this->assertSame('Saturday 3 Oct 2026: 13.6 km, 2 runs. Show details', $cell->detailButtonName());
        $this->assertSame('Saturday 3 Oct 2026: 13.6 km', $cell->accessibleName(), 'The plain name is unchanged.');
    }

    private function dayRun(): DayRun
    {
        return new DayRun('Morning run', 8_200, 2_650, 323, 45, 152);
    }
}
