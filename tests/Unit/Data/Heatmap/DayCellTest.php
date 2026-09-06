<?php

namespace Tests\Unit\Data\Heatmap;

use App\Data\Heatmap\DayCell;
use App\Enums\DayCellState;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DayCellTest extends TestCase
{
    public function test_kilometres_renders_to_one_decimal(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_200, DayCellState::Ran);

        $this->assertSame('8.2', $cell->kilometres());
    }

    public function test_kilometres_rounds_down_just_below_the_half_step(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_249, DayCellState::Ran);

        $this->assertSame('8.2', $cell->kilometres());
    }

    public function test_kilometres_rounds_up_at_the_half_step(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_250, DayCellState::Ran);

        $this->assertSame('8.3', $cell->kilometres());
    }

    public function test_the_accessible_name_of_a_ran_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_200, DayCellState::Ran);

        $this->assertSame('Tuesday 19 May 2026: 8.2 km', $cell->accessibleName());
    }

    public function test_the_accessible_name_of_a_rest_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-18'), 0, DayCellState::Rest);

        $this->assertSame('Monday 18 May 2026: rest day, 0 km', $cell->accessibleName());
    }

    public function test_the_accessible_name_of_a_future_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-09-06'), 0, DayCellState::Future);

        $this->assertSame('Sunday 6 Sep 2026: not yet', $cell->accessibleName());
    }

    public function test_the_pointer_title_of_a_ran_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-19'), 8_200, DayCellState::Ran);

        $this->assertSame('Tuesday 19 May 2026 — 8.2 km', $cell->pointerTitle());
    }

    public function test_the_pointer_title_of_a_rest_day(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-05-18'), 0, DayCellState::Rest);

        $this->assertSame('Monday 18 May 2026 — rest', $cell->pointerTitle());
    }

    public function test_a_future_day_carries_no_pointer_title(): void
    {
        $cell = new DayCell(CarbonImmutable::parse('2026-09-06'), 0, DayCellState::Future);

        $this->assertNull($cell->pointerTitle());
    }
}
