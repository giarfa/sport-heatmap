<?php

namespace Tests\Feature\Garmin;

use App\Data\Garmin\GarminCsvRow;
use App\Data\Garmin\ParsedGarminCsv;
use App\Enums\SportType;
use App\Exceptions\Garmin\GarminCsvRejectedException;
use App\Support\Garmin\ParseGarminCsv;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ParseGarminCsvTest extends TestCase
{
    private const IT_HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento';

    /**
     * Files written by this test, removed in tearDown().
     *
     * @var array<int, string>
     */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-11 12:00:00');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_the_italian_client_sample_parses_to_37_runs_and_ignores_the_hikes(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it.csv'));

        $this->assertCount(37, $parsed->rows);
        $this->assertSame([SportType::Run], array_values(array_unique(array_map(
            fn (GarminCsvRow $row): SportType => $row->sportType,
            $parsed->rows,
        ), SORT_REGULAR)));
        $this->assertSame(['Escursionismo' => 3], $parsed->ignoredByType);
        $this->assertSame(0, $parsed->futureDated);
        $this->assertSame([], $parsed->invalid);

        $first = $parsed->rows[0];
        $this->assertSame('Paese Corsa', $first->name);
        $this->assertSame(8_290, $first->distanceMeters);
        $this->assertSame(3_263, $first->movingTimeSeconds);
        $this->assertSame('2026-10-10 09:33:27', $first->startDateLocal->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10', $first->activityDate->toDateString());
    }

    public function test_the_english_fixture_maps_each_running_variant_and_ignores_hiking_by_name(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-en.csv'));

        $sportTypes = [];
        foreach ($parsed->rows as $row) {
            $sportTypes[$row->name] = $row->sportType;
        }

        $this->assertSame(SportType::Run, $sportTypes['Verona Running']);
        $this->assertSame(SportType::Run, $sportTypes['Treadmill Run']);
        $this->assertSame(SportType::TrailRun, $sportTypes['Ridge Trail']);
        $this->assertSame(SportType::VirtualRun, $sportTypes['Zwift Run']);
        $this->assertSame(['Hiking' => 1], $parsed->ignoredByType);
    }

    public function test_a_missing_distance_is_listed_with_its_line_while_the_rest_still_parse(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-en.csv'));

        $this->assertContains(['line' => 7, 'reason' => 'missing distance'], $parsed->invalid);
        $this->assertCount(7, $parsed->rows);
    }

    public function test_a_missing_moving_time_falls_back_to_the_time_column(): void
    {
        $longRun = $this->rowNamed($this->parse(base_path('tests/Fixtures/Garmin/activities-en.csv')), 'Long Run');

        $this->assertSame(6_930, $longRun->movingTimeSeconds);
    }

    public function test_a_thousands_separator_and_fractional_seconds_are_handled(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-en.csv'));

        $this->assertSame(1_005_200, $this->rowNamed($parsed, 'Self-transcendence 1000 km')->distanceMeters);
        $this->assertSame(2_413, $this->rowNamed($parsed, 'Zwift Run')->movingTimeSeconds);
    }

    public function test_an_unreadable_date_is_invalid_and_a_future_dated_row_is_counted_but_an_old_one_is_kept(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-en.csv'));

        $this->assertContains(['line' => 10, 'reason' => 'unreadable date'], $parsed->invalid);
        $this->assertSame(1, $parsed->futureDated);
        $this->assertSame('2026-05-01', $this->rowNamed($parsed, 'Spring Run')->activityDate->toDateString());
    }

    public function test_a_row_with_neither_moving_time_nor_time_is_invalid(): void
    {
        $parsed = $this->parse($this->csv(self::IT_HEADER, [
            'Corsa,2026-10-10 09:33:27,false,"Run","8.29","--","--"',
        ]));

        $this->assertSame([['line' => 2, 'reason' => 'missing moving time']], $parsed->invalid);
    }

    public function test_an_overflowing_date_is_invalid_rather_than_rolled_over(): void
    {
        $parsed = $this->parse($this->csv(self::IT_HEADER, [
            'Corsa,2026-09-31 09:33:27,false,"Run","8.29","00:54:24","00:54:23"',
        ]));

        $this->assertSame([['line' => 2, 'reason' => 'unreadable date']], $parsed->invalid);
    }

    public function test_a_malformed_or_overflowing_distance_is_unreadable_not_missing(): void
    {
        $parsed = $this->parse($this->csv(self::IT_HEADER, [
            'Corsa,2026-10-10 09:33:27,false,"Run","8,29 km","00:54:24","00:54:23"',
            'Corsa,2026-10-09 09:33:27,false,"Run","99999999999999999999999","00:54:24","00:54:23"',
        ]));

        $this->assertSame([
            ['line' => 2, 'reason' => 'unreadable distance'],
            ['line' => 3, 'reason' => 'unreadable distance'],
        ], $parsed->invalid);
    }

    public function test_columns_are_found_by_name_even_when_the_type_column_is_not_first(): void
    {
        $parsed = $this->parse($this->csv('Data,Titolo,Tipo di attività,Distanza,Tempo in movimento', [
            '2026-10-10 09:33:27,"Paese Corsa",Corsa,"8.29","00:54:23"',
        ]));

        $this->assertSame(8_290, $parsed->rows[0]->distanceMeters);
    }

    public function test_more_than_five_thousand_runs_is_rejected(): void
    {
        $lines = [];
        $start = Carbon::parse('2026-10-10 00:00:00');
        for ($second = 0; $second <= 5_000; $second++) {
            $lines[] = sprintf('Corsa,%s,false,"Run","5.00","00:30:00","00:30:00"', $start->copy()->addSeconds($second)->format('Y-m-d H:i:s'));
        }

        $this->assertRejected(
            'The file has more than 5000 runs. Export a shorter period from Garmin Connect.',
            $this->csv(self::IT_HEADER, $lines),
        );
    }

    public function test_exactly_five_thousand_runs_are_accepted(): void
    {
        $lines = [];
        $start = Carbon::parse('2026-10-10 00:00:00');
        for ($second = 0; $second < 5_000; $second++) {
            $lines[] = sprintf('Corsa,%s,false,"Run","5.00","00:30:00","00:30:00"', $start->copy()->addSeconds($second)->format('Y-m-d H:i:s'));
        }

        $this->assertCount(5_000, $this->parse($this->csv(self::IT_HEADER, $lines))->rows);
    }

    public function test_more_than_a_hundred_unreadable_rows_is_rejected(): void
    {
        $this->assertRejected(
            'More than 100 rows could not be read. Export the Activities list again from Garmin Connect.',
            $this->csv(self::IT_HEADER, array_fill(0, 101, 'Corsa,not-a-date,false,"Run","5.00","00:30:00","00:30:00"')),
        );
    }

    public function test_ignored_type_names_are_capped_in_length_and_number(): void
    {
        $lines = ['"'.str_repeat('X', 500).'",2026-10-10 09:33:27,false,"Long","5.00","00:30:00","00:30:00"'];
        for ($type = 1; $type <= 60; $type++) {
            $lines[] = "Type {$type},2026-10-10 09:33:27,false,\"Other\",\"5.00\",\"00:30:00\",\"00:30:00\"";
        }

        $parsed = $this->parse($this->csv(self::IT_HEADER, $lines));

        $this->assertCount(51, $parsed->ignoredByType);
        $this->assertArrayHasKey(str_repeat('X', 60), $parsed->ignoredByType);
        $this->assertSame(11, $parsed->ignoredByType['(other types)']);
    }

    public function test_identical_rows_within_one_file_collapse_into_one(): void
    {
        $row = 'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23"';

        $parsed = $this->parse($this->csv(self::IT_HEADER, [$row, $row]));

        $this->assertCount(1, $parsed->rows);
    }

    public function test_a_byte_order_mark_on_the_header_is_accepted(): void
    {
        $parsed = $this->parse($this->csv("\u{FEFF}".self::IT_HEADER, [
            'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23"',
        ]));

        $this->assertCount(1, $parsed->rows);
    }

    public function test_an_empty_title_falls_back_to_run(): void
    {
        $parsed = $this->parse($this->csv(self::IT_HEADER, [
            'Corsa,2026-10-10 09:33:27,false,"","8.29","00:54:24","00:54:23"',
        ]));

        $this->assertSame('Run', $parsed->rows[0]->name);
    }

    public function test_a_strava_export_header_is_rejected_as_unrecognised(): void
    {
        $this->assertRejected(
            'This is not a Garmin Connect activities export. Use the Italian or English Activities CSV.',
            $this->csv('Activity ID,Activity Date,Activity Name,Activity Type,Elapsed Time,Distance', [
                '123,"Oct 10, 2026, 9:33:27 AM",Morning Run,Run,3264,8.29',
            ]),
        );
    }

    public function test_a_file_missing_the_distance_column_is_rejected_naming_it(): void
    {
        $this->assertRejected(
            'The file is missing the “Distanza” column. Export the Activities list again from Garmin Connect.',
            $this->csv('Tipo di attività,Data,Preferita,Titolo,Tempo,Tempo in movimento', [
                'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","00:54:24","00:54:23"',
            ]),
        );
    }

    public function test_binary_content_is_rejected_as_not_a_csv(): void
    {
        $this->assertRejected('This is not a CSV file.', $this->file("PK\x03\x04\x14\x00\x06\x00\x08\x00\x00\x00!\x00\xff\xfe"));
    }

    public function test_a_header_only_file_is_rejected_as_empty(): void
    {
        $this->assertRejected('The file has no activities.', $this->csv(self::IT_HEADER, []));
    }

    public function test_an_empty_file_is_rejected_as_empty(): void
    {
        $this->assertRejected('The file has no activities.', $this->file(''));
    }

    public function test_the_italian_metric_columns_parse_to_whole_seconds_metres_and_bpm(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it-metrics.csv'));

        $this->assertCount(5, $parsed->rows);
        $this->assertSame(['Escursionismo' => 1], $parsed->ignoredByType);

        $first = $this->rowNamed($parsed, 'Paese Corsa');
        $this->assertSame(323, $first->averagePaceSecondsPerKm);
        $this->assertSame(45, $first->totalAscentMeters);
        $this->assertSame(152, $first->averageHeartRate);

        $hill = $this->rowNamed($parsed, 'Collina');
        $this->assertSame(348, $hill->averagePaceSecondsPerKm);
        $this->assertSame(1_234, $hill->totalAscentMeters, 'The thousands separator is not a decimal mark.');
        $this->assertSame(149, $hill->averageHeartRate, 'A decimal heart rate rounds to whole bpm.');
    }

    public function test_the_english_metric_columns_parse_the_same_way(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-en-metrics.csv'));

        $first = $this->rowNamed($parsed, 'Paese Run');
        $this->assertSame(323, $first->averagePaceSecondsPerKm);
        $this->assertSame(45, $first->totalAscentMeters);
        $this->assertSame(152, $first->averageHeartRate);

        $hill = $this->rowNamed($parsed, 'Hill run');
        $this->assertSame(1_234, $hill->totalAscentMeters);
        $this->assertSame(149, $hill->averageHeartRate);
    }

    public function test_a_pace_may_arrive_as_hours_minutes_seconds(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it-metrics.csv'));

        $this->assertSame(3_910, $this->rowNamed($parsed, 'Passeggiata di corsa')->averagePaceSecondsPerKm);
    }

    public function test_a_flat_run_keeps_a_zero_ascent_but_a_zero_heart_rate_is_not_recorded(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it-metrics.csv'));

        $row = $this->rowNamed($parsed, 'Passeggiata di corsa');
        $this->assertSame(0, $row->totalAscentMeters);
        $this->assertNull($row->averageHeartRate);
    }

    public function test_an_unreadable_metric_leaves_only_that_metric_empty_and_the_row_is_not_invalid(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it-metrics.csv'));

        $row = $this->rowNamed($parsed, 'Passo illeggibile');
        $this->assertNull($row->averagePaceSecondsPerKm);
        $this->assertSame(20, $row->totalAscentMeters);
        $this->assertSame(140, $row->averageHeartRate);
        $this->assertSame([], $parsed->invalid);
        $this->assertSame(5_000, $row->distanceMeters);
    }

    public function test_double_dash_metrics_are_empty_never_zero(): void
    {
        $row = $this->rowNamed($this->parse(base_path('tests/Fixtures/Garmin/activities-it-metrics.csv')), 'Senza metriche');

        $this->assertNull($row->averagePaceSecondsPerKm);
        $this->assertNull($row->totalAscentMeters);
        $this->assertNull($row->averageHeartRate);
        $this->assertSame([], $row->metricAttributes());
    }

    public function test_every_run_of_the_verbatim_client_sample_has_empty_metrics(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it.csv'));

        $this->assertCount(37, $parsed->rows);

        foreach ($parsed->rows as $row) {
            $this->assertNull($row->averagePaceSecondsPerKm);
            $this->assertNull($row->totalAscentMeters);
            $this->assertNull($row->averageHeartRate);
        }
    }

    public function test_a_file_without_the_metric_columns_still_parses_with_empty_metrics(): void
    {
        $parsed = $this->parse($this->csv(self::IT_HEADER, [
            'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23"',
        ]));

        $this->assertCount(1, $parsed->rows);
        $this->assertNull($parsed->rows[0]->averagePaceSecondsPerKm);
        $this->assertNull($parsed->rows[0]->totalAscentMeters);
        $this->assertNull($parsed->rows[0]->averageHeartRate);
    }

    public function test_an_oversized_metric_value_is_not_recorded_instead_of_overflowing(): void
    {
        $parsed = $this->parse($this->csv(self::IT_HEADER.',Passo medio,Ascesa totale,FC Media', [
            'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23","99999999:99:99","99999999999","99999"',
        ]));

        $this->assertNull($parsed->rows[0]->averagePaceSecondsPerKm);
        $this->assertNull($parsed->rows[0]->totalAscentMeters);
        $this->assertNull($parsed->rows[0]->averageHeartRate);
        $this->assertSame([], $parsed->invalid);
    }

    public function test_a_metric_no_run_can_have_is_not_recorded(): void
    {
        $parsed = $this->parse($this->csv(self::IT_HEADER.',Passo medio,Ascesa totale,FC Media', [
            'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23","20:00:00","25,000","999"',
        ]));

        $this->assertNull($parsed->rows[0]->averagePaceSecondsPerKm);
        $this->assertNull($parsed->rows[0]->totalAscentMeters);
        $this->assertNull($parsed->rows[0]->averageHeartRate);
    }

    public function test_the_metrics_are_not_part_of_a_runs_identity(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it-metrics.csv'));
        $row = $this->rowNamed($parsed, 'Paese Corsa');

        $withoutMetrics = new GarminCsvRow(
            $row->sportType,
            $row->name,
            $row->distanceMeters,
            $row->movingTimeSeconds,
            $row->startDateLocal,
            $row->activityDate,
        );

        $this->assertSame($withoutMetrics->identityKey(), $row->identityKey());
    }

    public function test_the_metrics_survive_the_session_round_trip_and_an_older_pending_upload_still_rebuilds(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it-metrics.csv'));
        $rebuilt = ParsedGarminCsv::fromSessionArray($parsed->toSessionArray());

        $this->assertEquals($parsed->rows, $rebuilt->rows);

        $beforeTheDeploy = $parsed->toSessionArray();

        foreach ($beforeTheDeploy['rows'] as $index => $row) {
            unset(
                $beforeTheDeploy['rows'][$index]['average_pace_seconds_per_km'],
                $beforeTheDeploy['rows'][$index]['total_ascent_meters'],
                $beforeTheDeploy['rows'][$index]['average_heart_rate'],
            );
        }

        $old = ParsedGarminCsv::fromSessionArray($beforeTheDeploy);

        $this->assertCount(5, $old->rows);
        $this->assertNull($old->rows[0]->averagePaceSecondsPerKm);
        $this->assertSame($parsed->rows[0]->identityKey(), $old->rows[0]->identityKey());
    }

    public function test_a_rows_attributes_carry_only_the_metrics_the_file_recorded(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-it-metrics.csv'));

        $this->assertSame([
            'average_pace_seconds_per_km' => 323,
            'total_ascent_meters' => 45,
            'average_heart_rate' => 152,
        ], $this->rowNamed($parsed, 'Paese Corsa')->metricAttributes());

        $partial = $this->rowNamed($parsed, 'Passo illeggibile')->toAttributes();
        $this->assertArrayNotHasKey('average_pace_seconds_per_km', $partial);
        $this->assertSame(20, $partial['total_ascent_meters']);
    }

    private function parse(string $path): ParsedGarminCsv
    {
        return app(ParseGarminCsv::class)($path, now());
    }

    private function assertRejected(string $message, string $path): void
    {
        try {
            $this->parse($path);
            $this->fail('The file should have been rejected.');
        } catch (GarminCsvRejectedException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function rowNamed(ParsedGarminCsv $parsed, string $name): GarminCsvRow
    {
        foreach ($parsed->rows as $row) {
            if ($row->name === $name) {
                return $row;
            }
        }

        $this->fail("No parsed row is named {$name}.");
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(string $header, array $lines): string
    {
        return $this->file(implode("\n", [$header, ...$lines])."\n");
    }

    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'garmin-csv-');
        file_put_contents($path, $contents);

        return $this->temporaryFiles[] = $path;
    }
}
