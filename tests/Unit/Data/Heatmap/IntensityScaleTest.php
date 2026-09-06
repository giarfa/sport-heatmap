<?php

namespace Tests\Unit\Data\Heatmap;

use App\Data\Heatmap\IntensityScale;
use Tests\TestCase;

class IntensityScaleTest extends TestCase
{
    public function test_a_distance_exactly_on_p25_lands_in_level_one(): void
    {
        $scale = IntensityScale::calibrated(7_200, 9_900, 14_100, 68);

        $this->assertSame(1, $scale->levelFor(7_200));
    }

    public function test_one_metre_above_p25_lands_in_level_two(): void
    {
        $scale = IntensityScale::calibrated(7_200, 9_900, 14_100, 68);

        $this->assertSame(2, $scale->levelFor(7_201));
    }

    public function test_a_distance_exactly_on_p50_lands_in_level_two(): void
    {
        $scale = IntensityScale::calibrated(7_200, 9_900, 14_100, 68);

        $this->assertSame(2, $scale->levelFor(9_900));
    }

    public function test_one_metre_above_p50_lands_in_level_three(): void
    {
        $scale = IntensityScale::calibrated(7_200, 9_900, 14_100, 68);

        $this->assertSame(3, $scale->levelFor(9_901));
    }

    public function test_a_distance_exactly_on_p75_lands_in_level_three(): void
    {
        $scale = IntensityScale::calibrated(7_200, 9_900, 14_100, 68);

        $this->assertSame(3, $scale->levelFor(14_100));
    }

    public function test_one_metre_above_p75_lands_in_level_four(): void
    {
        $scale = IntensityScale::calibrated(7_200, 9_900, 14_100, 68);

        $this->assertSame(4, $scale->levelFor(14_101));
    }

    public function test_zero_metres_is_level_zero_on_a_calibrated_scale(): void
    {
        $scale = IntensityScale::calibrated(7_200, 9_900, 14_100, 68);

        $this->assertSame(0, $scale->levelFor(0));
    }

    public function test_zero_metres_is_level_zero_on_a_fixed_band_scale(): void
    {
        $scale = IntensityScale::fixedBands(3, degenerate: false);

        $this->assertSame(0, $scale->levelFor(0));
    }

    public function test_legend_rows_for_a_calibrated_scale(): void
    {
        $scale = IntensityScale::calibrated(7_200, 9_900, 14_100, 68);
        $rows = $scale->legendRows();

        $this->assertCount(5, $rows);
        $this->assertSame(['Rest', 'Easy', 'Steady', 'Hard', 'Peak'], array_map(fn ($row) => $row->name, $rows));
        $this->assertSame('0 km', $rows[0]->rangeLabel);
        $this->assertSame('0.1 – 7.2 km', $rows[1]->rangeLabel);
        $this->assertSame('7.2 – 9.9 km', $rows[2]->rangeLabel);
        $this->assertSame('9.9 – 14.1 km', $rows[3]->rangeLabel);
        $this->assertSame('14.1+ km', $rows[4]->rangeLabel);
        $this->assertSame(['rest', 'l1', 'l2', 'l3', 'l4'], array_map(fn ($row) => $row->modifier(), $rows));
    }

    public function test_legend_rows_for_a_fixed_band_scale(): void
    {
        $scale = IntensityScale::fixedBands(3, degenerate: false);
        $rows = $scale->legendRows();

        $this->assertCount(5, $rows);
        $this->assertSame(['Rest', 'Easy', 'Steady', 'Hard', 'Peak'], array_map(fn ($row) => $row->name, $rows));
        $this->assertSame('0 km', $rows[0]->rangeLabel);
        $this->assertSame('0.1 – 5.0 km', $rows[1]->rangeLabel);
        $this->assertSame('5.0 – 10.0 km', $rows[2]->rangeLabel);
        $this->assertSame('10.0 – 20.0 km', $rows[3]->rangeLabel);
        $this->assertSame('20.0+ km', $rows[4]->rangeLabel);
    }
}
