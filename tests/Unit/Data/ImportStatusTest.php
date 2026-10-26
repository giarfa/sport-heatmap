<?php

namespace Tests\Unit\Data;

use App\Data\ImportStatus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-05 09:00:00');
    }

    public function test_with_no_import_it_says_no_runs_are_imported_yet(): void
    {
        $status = new ImportStatus(null, 0);

        $this->assertFalse($status->hasImported());
        $this->assertSame('No runs imported yet', $status->chipLabel());
        $this->assertSame('no runs imported yet', $status->footerLabel());
        $this->assertSame('Never', $status->lastImportLabel());
        $this->assertNull($status->lastImportRelative());
    }

    public function test_it_reads_last_import_relative_to_now(): void
    {
        $status = new ImportStatus(now()->subDays(3), 12);

        $this->assertTrue($status->hasImported());
        $this->assertSame('Last import 3 days ago', $status->chipLabel());
        $this->assertSame('last import 3 days ago', $status->footerLabel());
        $this->assertSame('3 days ago', $status->lastImportRelative());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function timezones(): array
    {
        return [
            'summer in Rome' => ['Europe/Rome', '5 Sep 2026, 01:30'],
            'UTC' => ['UTC', '4 Sep 2026, 23:30'],
        ];
    }

    #[DataProvider('timezones')]
    public function test_the_absolute_stamp_is_printed_in_the_display_timezone(string $timezone, string $expected): void
    {
        Config::set('heatmap.display_timezone', $timezone);

        $status = new ImportStatus(Date::parse('2026-09-04 23:30:00', 'UTC'), 1);

        $this->assertSame($expected, $status->lastImportLabel());
    }

    public function test_the_detail_card_is_headed_import(): void
    {
        $this->assertSame('Import', (new ImportStatus(null, 0))->detailHeading());
    }

    public function test_the_empty_window_note_names_the_import_as_the_way_the_map_fills(): void
    {
        $this->assertSame(
            'No runs found in the last 16 weeks. The map fills in after your next import.',
            (new ImportStatus(null, 0))->emptyWindowNote(),
        );
    }
}
