<?php

namespace Tests\Unit\Data\Heatmap;

use App\Data\Heatmap\DayCell;
use App\Data\Heatmap\WeekRow;
use App\Enums\DayCellState;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class WeekRowTest extends TestCase
{
    public function test_kilometres_renders_the_weeks_total_to_one_decimal(): void
    {
        $row = new WeekRow(CarbonImmutable::parse('2026-05-18'), $this->sevenRestDays(), 38_800, false, false);

        $this->assertSame('38.8', $row->kilometres());
    }

    public function test_the_week_label_is_the_short_start_date(): void
    {
        $row = new WeekRow(CarbonImmutable::parse('2026-05-18'), $this->sevenRestDays(), 0, false, false);

        $this->assertSame('18 May', $row->weekLabel());
    }

    public function test_a_week_row_rejects_a_construction_with_the_wrong_number_of_days(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WeekRow(CarbonImmutable::parse('2026-05-18'), array_slice($this->sevenRestDays(), 0, 6), 0, false, false);
    }

    /**
     * @return array<int, DayCell>
     */
    private function sevenRestDays(): array
    {
        $weekStart = CarbonImmutable::parse('2026-05-18');

        return array_map(
            static fn (int $offset): DayCell => new DayCell($weekStart->addDays($offset), 0, DayCellState::Rest),
            range(0, 6),
        );
    }
}
