<?php

namespace Tests\Unit\Data\Garmin;

use App\Data\Garmin\ExtendedRunMetrics;
use Tests\TestCase;

class ExtendedRunMetricsTest extends TestCase
{
    /**
     * Raw cells in the real formats a Garmin export uses.
     *
     * @return array<string, string>
     */
    private function italianCells(): array
    {
        return [
            'calories' => '1,028',
            'max_hr' => '171',
            'aerobic_te' => '3.8',
            'avg_cadence' => '168',
            'max_cadence' => '181',
            'best_pace' => '4:12',
            'total_descent' => '139',
            'stride_length' => '1.00',
            'vertical_ratio' => '9.4',
            'vertical_oscillation' => '9.6',
            'ground_contact_time' => '302',
            'gct_balance' => '49.8% S / 50.2% D',
            'steps' => '15,490',
            'laps' => '17',
            'elapsed_time' => '01:33:40',
            'min_elevation' => '1,065',
            'max_elevation' => '1,100',
            'training_stress_score' => '48.3',
        ];
    }

    public function test_every_csv_value_is_read_and_normalised(): void
    {
        $metrics = ExtendedRunMetrics::fromCsvValues($this->italianCells());

        $this->assertSame([
            'calories' => 1_028,
            'max_heart_rate' => 171,
            'aerobic_training_effect' => 3.8,
            'average_running_cadence' => 168,
            'max_running_cadence' => 181,
            'best_pace_seconds_per_km' => 252,
            'total_descent_meters' => 139,
            'average_stride_length_cm' => 100.0,
            'average_vertical_ratio_percent' => 9.4,
            'average_vertical_oscillation_cm' => 9.6,
            'average_ground_contact_time_ms' => 302,
            'average_ground_contact_balance_left_percent' => 49.8,
            'steps' => 15_490,
            'lap_count' => 17,
            'elapsed_time_seconds' => 5_620,
            'min_elevation_meters' => 1_065,
            'max_elevation_meters' => 1_100,
            'training_stress_score' => 48.3,
        ], $metrics->toAttributes());
    }

    public function test_the_csv_never_carries_the_api_only_metrics(): void
    {
        $metrics = ExtendedRunMetrics::fromCsvValues($this->italianCells());

        $this->assertNull($metrics->hrZone1Seconds);
        $this->assertNull($metrics->fastest5kSeconds);
        $this->assertNull($metrics->locationName);
    }

    public function test_the_stride_is_converted_from_metres_without_losing_a_centimetre(): void
    {
        $metres = fn (string $stride): ?float => ExtendedRunMetrics::fromCsvValues(['stride_length' => $stride])->averageStrideLengthCm;

        $this->assertSame(104.0, $metres('1.04'));
        $this->assertSame(98.5, $metres('0.985'));
        $this->assertNull($metres('0.00'));
        $this->assertNull($metres('5.01'));
        $this->assertNull($metres('-1.00'));
    }

    public function test_a_real_zero_is_kept_where_garmin_reports_one(): void
    {
        $metrics = ExtendedRunMetrics::fromCsvValues([
            'aerobic_te' => '0.0',
            'total_descent' => '0',
            'min_elevation' => '0',
            'max_elevation' => '-12',
        ]);

        $this->assertSame(0.0, $metrics->aerobicTrainingEffect);
        $this->assertSame(0, $metrics->totalDescentMeters);
        $this->assertSame(0, $metrics->minElevationMeters);
        $this->assertSame(-12, $metrics->maxElevationMeters);
    }

    public function test_a_zero_that_means_not_recorded_is_empty(): void
    {
        $metrics = ExtendedRunMetrics::fromCsvValues([
            'calories' => '0',
            'max_hr' => '0',
            'avg_cadence' => '0',
            'steps' => '0',
            'laps' => '0',
            'ground_contact_time' => '0',
            'training_stress_score' => '0.0',
            'elapsed_time' => '00:00:00',
        ]);

        $this->assertTrue($metrics->isEmpty());
    }

    public function test_the_placeholder_unreadable_and_out_of_bound_cells_are_empty_never_zero(): void
    {
        $placeholders = array_fill_keys(array_keys($this->italianCells()), '--');
        $unreadable = [
            'calories' => 'abc', 'max_hr' => '300', 'aerobic_te' => '5.7', 'avg_cadence' => '0', 'max_cadence' => '999',
            'best_pace' => '4:xx', 'total_descent' => '-4', 'stride_length' => '-1.00', 'vertical_ratio' => '140.0',
            'vertical_oscillation' => '61.5', 'ground_contact_time' => '3000', 'gct_balance' => '50% X / 50% Y',
            'steps' => 'many', 'laps' => '0', 'elapsed_time' => '99999:00:00', 'min_elevation' => '99999',
            'max_elevation' => '-900', 'training_stress_score' => '99999.9',
        ];

        $this->assertTrue(ExtendedRunMetrics::fromCsvValues($placeholders)->isEmpty());
        $this->assertTrue(ExtendedRunMetrics::fromCsvValues($unreadable)->isEmpty());
        $this->assertSame([], ExtendedRunMetrics::fromCsvValues($unreadable)->toAttributes());
    }

    public function test_a_column_the_file_lacks_reads_as_not_recorded(): void
    {
        $metrics = ExtendedRunMetrics::fromCsvValues(['calories' => '512']);

        $this->assertSame(['calories' => 512], $metrics->toAttributes());
    }

    public function test_a_default_instance_is_empty_and_has_no_attributes(): void
    {
        $this->assertTrue((new ExtendedRunMetrics)->isEmpty());
        $this->assertSame([], (new ExtendedRunMetrics)->toAttributes());
    }

    public function test_the_session_form_holds_only_the_recorded_values_and_round_trips(): void
    {
        $metrics = ExtendedRunMetrics::fromCsvValues($this->italianCells());

        $this->assertEquals($metrics, ExtendedRunMetrics::fromSessionArray($metrics->toSessionArray()));
        $this->assertSame(['calories' => 512], ExtendedRunMetrics::fromCsvValues(['calories' => '512'])->toSessionArray());
        $this->assertSame([], (new ExtendedRunMetrics)->toSessionArray());
    }

    public function test_a_session_written_before_a_column_existed_still_rebuilds(): void
    {
        $metrics = ExtendedRunMetrics::fromSessionArray(['calories' => 512]);

        $this->assertSame(512, $metrics->calories);
        $this->assertNull($metrics->trainingStressScore);
        $this->assertTrue(ExtendedRunMetrics::fromSessionArray([])->isEmpty());
    }

    public function test_a_session_value_of_the_wrong_type_is_dropped_rather_than_stored(): void
    {
        $metrics = ExtendedRunMetrics::fromSessionArray(['calories' => 'lots', 'location_name' => 42, 'steps' => '8240']);

        $this->assertNull($metrics->calories);
        $this->assertNull($metrics->locationName);
        $this->assertSame(8_240, $metrics->steps);
    }
}
