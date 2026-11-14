<?php

namespace Tests\Unit\Data;

use App\Data\ImportStatus;
use App\Enums\GarminSyncFailure;
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

    public function test_the_chip_reads_the_more_recent_of_the_last_sync_and_the_last_import(): void
    {
        $sync = now()->subHours(2);
        $import = now()->subDays(1);

        $this->assertSame('Last sync 2 hours ago', (new ImportStatus($import, 4, $sync, null, true))->chipLabel());
        $this->assertSame('Last import 1 hour ago', (new ImportStatus(now()->subHour(), 4, $sync, null, true))->chipLabel());
        $this->assertSame('Last sync 2 hours ago', (new ImportStatus(null, 0, $sync, null, true))->chipLabel());
        $this->assertSame('Last sync 2 hours ago', (new ImportStatus($sync, 4, $sync, null, true))->chipLabel(), 'A tie goes to the sync.');
        $this->assertSame('last sync 2 hours ago', (new ImportStatus($import, 4, $sync, null, true))->footerLabel());
    }

    public function test_a_configured_sync_with_nothing_yet_says_not_synced_yet(): void
    {
        $this->assertSame('Not synced yet', (new ImportStatus(null, 0, null, null, true))->chipLabel());
        $this->assertSame('No runs imported yet', (new ImportStatus(null, 0))->chipLabel());
    }

    public function test_the_sync_stamp_is_printed_in_the_display_timezone(): void
    {
        Config::set('heatmap.display_timezone', 'Europe/Rome');

        $status = new ImportStatus(null, 0, Date::parse('2026-09-04 23:30:00', 'UTC'), null, true);

        $this->assertSame('5 Sep 2026, 01:30', $status->lastSyncLabel());
        $this->assertSame('Never', (new ImportStatus(null, 0, null, null, true))->lastSyncLabel());
        $this->assertNull((new ImportStatus(null, 0, null, null, true))->lastSyncRelative());
    }

    public function test_the_warning_is_the_latest_failures_copy_and_absent_otherwise(): void
    {
        $failed = new ImportStatus(null, 0, null, GarminSyncFailure::KeyRejected, true);

        $this->assertSame('Garmin key rejected — renew it', $failed->syncWarning());
        $this->assertSame(GarminSyncFailure::KeyRejected->advice(), $failed->syncWarningDetail());
        $this->assertNull((new ImportStatus(null, 0, now(), null, true))->syncWarning());
        $this->assertNull((new ImportStatus(null, 0))->syncWarningDetail());
    }

    public function test_the_empty_window_note_mentions_the_sync_only_when_it_is_configured(): void
    {
        $this->assertStringContainsString('next sync or import', (new ImportStatus(null, 0, null, null, true))->emptyWindowNote());
        $this->assertStringNotContainsString('sync', (new ImportStatus(null, 0))->emptyWindowNote());
    }
}
