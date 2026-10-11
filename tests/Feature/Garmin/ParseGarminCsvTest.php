<?php

namespace Tests\Feature\Garmin;

use App\Data\Garmin\GarminCsvRow;
use App\Data\Garmin\ParsedGarminCsv;
use App\Enums\SportType;
use App\Exceptions\Garmin\GarminCsvRejectedException;
use App\Support\Garmin\ParseGarminCsv;
use App\Support\Heatmap\HeatmapWindow;
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
        $this->assertSame(0, $parsed->outsideWindow);
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
        $this->assertCount(6, $parsed->rows);
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

    public function test_an_unreadable_date_is_invalid_and_future_and_out_of_window_rows_are_counted(): void
    {
        $parsed = $this->parse(base_path('tests/Fixtures/Garmin/activities-en.csv'));

        $this->assertContains(['line' => 10, 'reason' => 'unreadable date'], $parsed->invalid);
        $this->assertSame(2, $parsed->outsideWindow);
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

    private function parse(string $path): ParsedGarminCsv
    {
        return app(ParseGarminCsv::class)($path, HeatmapWindow::current(), now());
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
