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

    /**
     * The extended fields of an API payload, in the units the API uses.
     *
     * @return array<string, mixed>
     */
    private function apiPayload(): array
    {
        return [
            'calories' => 612.0,
            'maxHR' => 171.0,
            'aerobicTrainingEffect' => 3.84,
            'averageRunningCadenceInStepsPerMinute' => 168.4,
            'maxRunningCadenceInStepsPerMinute' => 181.0,
            'maxSpeed' => 3.968,
            'elevationLoss' => 41.2,
            'avgStrideLength' => 104.24,
            'avgVerticalRatio' => 8.74,
            'avgVerticalOscillation' => 9.63,
            'avgGroundContactTime' => 287.4,
            'avgGroundContactBalance' => 49.84,
            'steps' => 7_312,
            'lapCount' => 9,
            'elapsedDuration' => 3_301.2,
            'minElevation' => 61.2,
            'maxElevation' => 98.8,
            'hrTimeInZone_1' => 120.4,
            'hrTimeInZone_2' => 1_450.9,
            'hrTimeInZone_3' => 1_200.0,
            'hrTimeInZone_4' => 480.3,
            'hrTimeInZone_5' => 0.0,
            'fastestSplit_1000' => 291.6,
            'fastestSplit_1609' => 478.0,
            'fastestSplit_5000' => 1_530.2,
            'locationName' => '  Verona ',
            'startLatitude' => 45.4384,
            'startLongitude' => 10.9916,
            'endLatitude' => 45.4391,
            'endLongitude' => 10.9923,
        ];
    }

    public function test_every_api_field_lands_in_its_column_with_its_unit_and_rounding(): void
    {
        $this->assertSame([
            'calories' => 612,
            'max_heart_rate' => 171,
            'aerobic_training_effect' => 3.8,
            'average_running_cadence' => 168,
            'max_running_cadence' => 181,
            'best_pace_seconds_per_km' => 252,
            'total_descent_meters' => 41,
            'average_stride_length_cm' => 104.2,
            'average_vertical_ratio_percent' => 8.7,
            'average_vertical_oscillation_cm' => 9.6,
            'average_ground_contact_time_ms' => 287,
            'average_ground_contact_balance_left_percent' => 49.8,
            'steps' => 7_312,
            'lap_count' => 9,
            'elapsed_time_seconds' => 3_301,
            'min_elevation_meters' => 61,
            'max_elevation_meters' => 99,
            'hr_zone_1_seconds' => 120,
            'hr_zone_2_seconds' => 1_451,
            'hr_zone_3_seconds' => 1_200,
            'hr_zone_4_seconds' => 480,
            'hr_zone_5_seconds' => 0,
            'fastest_1k_seconds' => 292,
            'fastest_mile_seconds' => 478,
            'fastest_5k_seconds' => 1_530,
            'location_name' => 'Verona',
        ], ExtendedRunMetrics::fromApiPayload($this->apiPayload())->toAttributes());
    }

    public function test_the_api_never_carries_a_training_stress_score_and_no_coordinate_is_read(): void
    {
        $metrics = ExtendedRunMetrics::fromApiPayload([...$this->apiPayload(), 'trainingStressScore' => 88.0, 'tss' => 88.0]);

        $this->assertNull($metrics->trainingStressScore);
        $this->assertSame(
            [],
            array_filter(array_keys($metrics->toAttributes()), fn (string $column): bool => preg_match('/(^|_)(lat|latitude|lon|lng|longitude)(_|$)/', $column) === 1),
        );
    }

    public function test_the_best_pace_comes_from_the_top_speed(): void
    {
        $pace = fn (mixed $speed): ?int => ExtendedRunMetrics::fromApiPayload(['maxSpeed' => $speed])->bestPaceSecondsPerKm;

        $this->assertSame(250, $pace(4.0));
        $this->assertSame(333, $pace(3));
        $this->assertNull($pace(0));
        $this->assertNull($pace(-2.0));
        $this->assertNull($pace(null));
        $this->assertNull($pace('4.0'));
        $this->assertNull($pace(0.01), 'A pace slower than ten hours a kilometre is not a run.');
    }

    public function test_null_missing_wrong_typed_negative_and_out_of_bound_fields_are_empty_never_zero(): void
    {
        $this->assertTrue(ExtendedRunMetrics::fromApiPayload([])->isEmpty());

        $garbage = [
            'calories' => '612', 'maxHR' => [171], 'aerobicTrainingEffect' => true, 'averageRunningCadenceInStepsPerMinute' => null,
            'maxRunningCadenceInStepsPerMinute' => 9_999, 'elevationLoss' => -5, 'avgStrideLength' => ['cm' => 100],
            'avgVerticalRatio' => 250.0, 'avgVerticalOscillation' => '9.6', 'avgGroundContactTime' => 0, 'avgGroundContactBalance' => 0.0,
            'steps' => '8,240', 'lapCount' => 0, 'elapsedDuration' => -1, 'minElevation' => -9_999, 'maxElevation' => 99_999,
            'hrTimeInZone_1' => '120', 'hrTimeInZone_2' => -1, 'hrTimeInZone_3' => null, 'hrTimeInZone_4' => [], 'hrTimeInZone_5' => false,
            'fastestSplit_1000' => 0, 'fastestSplit_1609' => '478', 'fastestSplit_5000' => -60.0, 'locationName' => 42,
        ];

        $this->assertTrue(ExtendedRunMetrics::fromApiPayload($garbage)->isEmpty());
    }

    public function test_a_real_zero_is_kept_for_the_time_in_a_zone_the_descent_the_effect_and_the_elevation(): void
    {
        $metrics = ExtendedRunMetrics::fromApiPayload([
            'hrTimeInZone_1' => 0, 'elevationLoss' => 0.0, 'aerobicTrainingEffect' => 0.0, 'minElevation' => 0, 'maxElevation' => -2.0,
        ]);

        $this->assertSame(0, $metrics->hrZone1Seconds);
        $this->assertSame(0, $metrics->totalDescentMeters);
        $this->assertSame(0.0, $metrics->aerobicTrainingEffect);
        $this->assertSame(0, $metrics->minElevationMeters);
        $this->assertSame(-2, $metrics->maxElevationMeters);
    }

    public function test_the_place_name_is_trimmed_cut_at_255_and_empty_when_blank(): void
    {
        $name = fn (mixed $value): ?string => ExtendedRunMetrics::fromApiPayload(['locationName' => $value])->locationName;

        $this->assertSame('Lazise', $name('  Lazise '));
        $this->assertSame(255, mb_strlen((string) $name(str_repeat('è', 400))));
        $this->assertNull($name(''));
        $this->assertNull($name("  \t "));
        $this->assertNull($name(null));
    }

    public function test_the_same_run_from_the_csv_and_from_the_api_has_the_same_units(): void
    {
        $fromCsv = ExtendedRunMetrics::fromCsvValues([
            'stride_length' => '1.004',
            'vertical_oscillation' => '9.6',
            'vertical_ratio' => '8.7',
            'gct_balance' => '49.8% S / 50.2% D',
            'best_pace' => '4:12',
            'elapsed_time' => '00:55:01',
        ]);
        $fromApi = ExtendedRunMetrics::fromApiPayload([
            'avgStrideLength' => 100.4,
            'avgVerticalOscillation' => 9.6,
            'avgVerticalRatio' => 8.7,
            'avgGroundContactBalance' => 49.8,
            'maxSpeed' => 1_000 / 252,
            'elapsedDuration' => 3_301.0,
        ]);

        $this->assertEquals($fromCsv, $fromApi);
        $this->assertSame(100.4, $fromCsv->averageStrideLengthCm);
    }
}
