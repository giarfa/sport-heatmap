<?php

namespace Tests\Unit\Support\Heatmap;

use App\Support\Heatmap\CalibrateIntensityScale;
use Tests\TestCase;

class CalibrateIntensityScaleTest extends TestCase
{
    public function test_zero_non_zero_days_falls_back_to_fixed_bands_with_the_sparse_note(): void
    {
        $scale = (new CalibrateIntensityScale)(['d1' => 0, 'd2' => 0]);

        $this->assertFalse($scale->isCalibrated());
        $this->assertSame('Only 0 running days in this window — too few to calibrate, so fixed bands are shown instead.', $scale->note());
        $this->assertSame('0.1 – 5.0 km', $scale->legendRows()[1]->rangeLabel);
    }

    public function test_one_non_zero_day_falls_back_to_fixed_bands_with_the_sparse_note(): void
    {
        $scale = (new CalibrateIntensityScale)(['d1' => 6_000]);

        $this->assertFalse($scale->isCalibrated());
        $this->assertSame('Only 1 running day in this window — too few to calibrate, so fixed bands are shown instead.', $scale->note());
    }

    public function test_three_non_zero_days_falls_back_to_fixed_bands_with_the_sparse_note(): void
    {
        $scale = (new CalibrateIntensityScale)(['d1' => 6_000, 'd2' => 8_000, 'd3' => 0, 'd4' => 12_000]);

        $this->assertFalse($scale->isCalibrated());
        $this->assertSame('Only 3 running days in this window — too few to calibrate, so fixed bands are shown instead.', $scale->note());
    }

    public function test_four_distinct_non_zero_days_is_calibrated(): void
    {
        $scale = (new CalibrateIntensityScale)([
            'd1' => 1_000,
            'd2' => 2_000,
            'd3' => 3_000,
            'd4' => 4_000,
        ]);

        $this->assertTrue($scale->isCalibrated());
        $this->assertSame('Calibrated to your 4 running days in this window — recomputed after every import.', $scale->note());
    }

    public function test_zero_distance_days_are_excluded_from_the_distribution_not_counted_as_low_mileage(): void
    {
        $scale = (new CalibrateIntensityScale)([
            'd1' => 0,
            'd2' => 5_000,
            'd3' => 0,
            'd4' => 6_000,
            'd5' => 7_000,
            'd6' => 8_000,
        ]);

        $this->assertTrue($scale->isCalibrated());
        $this->assertSame('Calibrated to your 4 running days in this window — recomputed after every import.', $scale->note());
    }

    public function test_all_distances_identical_falls_back_to_fixed_bands_with_the_degenerate_note(): void
    {
        $scale = (new CalibrateIntensityScale)([
            'd1' => 5_000,
            'd2' => 5_000,
            'd3' => 5_000,
            'd4' => 5_000,
            'd5' => 5_000,
        ]);

        $this->assertFalse($scale->isCalibrated());
        $this->assertSame('The runs in this window are too alike to calibrate a scale — fixed bands are shown instead.', $scale->note());
    }

    public function test_a_general_p25_equals_p75_collapse_without_every_value_being_identical_falls_back_to_fixed_bands(): void
    {
        $scale = (new CalibrateIntensityScale)([
            'd1' => 1_000,
            'd2' => 5_000,
            'd3' => 5_000,
            'd4' => 5_000,
            'd5' => 5_000,
            'd6' => 5_000,
            'd7' => 5_000,
            'd8' => 9_000,
        ]);

        $this->assertFalse($scale->isCalibrated());
        $this->assertSame('The runs in this window are too alike to calibrate a scale — fixed bands are shown instead.', $scale->note());
    }

    /**
     * Method-pinning regression test: feeds the 68 running distances rendered
     * in .larapilot/mockups/heatmap-dashboard/index.html. R-7 linear
     * interpolation reproduces the mockup's legend — 7.2 / 9.9 / 14.1 km.
     * Nearest-rank instead yields 7.0 / 9.8 / 14.0 km and fails this test,
     * which is exactly the outcome this regression guards against.
     */
    public function test_the_mockups_68_day_dataset_calibrates_to_the_documented_thresholds(): void
    {
        $daily = [];

        foreach ($this->sixtyEightDayDistancesInMetres() as $index => $metres) {
            $daily["run-{$index}"] = $metres;
        }

        $scale = (new CalibrateIntensityScale)($daily);
        $rows = $scale->legendRows();

        $this->assertTrue($scale->isCalibrated());
        $this->assertSame('0.1 – 7.2 km', $rows[1]->rangeLabel);
        $this->assertSame('7.2 – 9.9 km', $rows[2]->rangeLabel);
        $this->assertSame('9.9 – 14.1 km', $rows[3]->rangeLabel);
        $this->assertSame('14.1+ km', $rows[4]->rangeLabel);
    }

    /**
     * The 68 running-day distances (in metres) rendered in
     * .larapilot/mockups/heatmap-dashboard/index.html.
     *
     * @return array<int, int>
     */
    private function sixtyEightDayDistancesInMetres(): array
    {
        $kilometres = [
            8.2, 10.1, 14.5, 6.0, 8.6, 6.2, 9.8, 16.2, 5.5, 9.0, 10.4, 18.0, 7.2, 7.0, 8.5, 12.0, 6.0,
            10.2, 12.4, 6.5, 20.1, 11.0, 7.0, 13.2, 22.5, 8.0, 6.4, 12.1, 14.0, 7.2, 24.2, 8.0, 9.5, 14.8,
            6.5, 5.0, 6.0, 7.5, 11.0, 5.8, 10.5, 12.8, 6.8, 21.0, 12.4, 8.0, 14.5, 26.4, 8.5, 6.8, 13.0,
            15.2, 7.5, 28.0, 7.0, 14.2, 8.5, 16.0, 32.1, 9.0, 10.0, 11.5, 6.0, 16.0, 5.5, 9.2, 11.0, 18.4,
        ];

        return array_map(fn (float $km): int => (int) round($km * 1_000), $kilometres);
    }
}
