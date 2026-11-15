<?php

namespace Tests\Feature\Dashboard;

use App\Enums\GarminSyncFailure;
use App\Models\Activity;
use App\Models\GarminSync;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dashboard's Garmin sync status: the chip and footer, the rail's "Last sync" row and
 * the failure banner — all of it only when a key is set, none of it otherwise.
 */
class SyncStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-11-14 09:00:00');

        Config::set('services.garmin.api_key', 'GCDC_status-test-key');

        $this->owner = $this->signInOwner();
    }

    public function test_a_sync_that_wrote_nothing_still_reads_as_the_last_sync(): void
    {
        GarminSync::factory()->succeeded()->create(['finished_at' => now()->subHours(2), 'new_count' => 0, 'updated_count' => 0]);

        $html = $this->page();

        $this->assertStringContainsString('Last sync 2 hours ago', $html);
        $this->assertStringContainsString('· last sync 2 hours ago', $html);
    }

    public function test_a_csv_import_after_the_last_sync_reads_as_the_last_import(): void
    {
        GarminSync::factory()->succeeded()->create(['finished_at' => now()->subHours(2)]);
        $this->csvRun(now()->subHour());

        $html = $this->page();

        $this->assertStringContainsString('Last import 1 hour ago', $html);
        $this->assertStringNotContainsString('Last sync 2 hours ago</', $html);
    }

    public function test_a_later_sync_reads_as_the_last_sync_again(): void
    {
        $this->csvRun(now()->subHour());
        GarminSync::factory()->succeeded()->create(['finished_at' => now()->subMinutes(30)]);

        $this->assertStringContainsString('Last sync 30 minutes ago', $this->page());
    }

    public function test_runs_the_sync_wrote_never_move_the_last_import(): void
    {
        $this->csvRun(now()->subDays(3));
        Activity::factory()->for($this->owner)->garminSynced()->create(['updated_at' => now()->subMinute()]);

        $html = $this->page();

        $this->assertStringContainsString('Last import 3 days ago', $html);
        $this->assertStringNotContainsString('Last import 1 minute ago', $html);
    }

    public function test_a_key_with_no_sync_and_no_import_yet_says_not_synced_yet(): void
    {
        $this->assertStringContainsString('Not synced yet', $this->page());
    }

    public function test_the_rail_card_shows_the_last_sync_stamp_in_the_display_timezone(): void
    {
        Config::set('heatmap.display_timezone', 'Europe/Rome');
        GarminSync::factory()->succeeded()->create(['finished_at' => '2026-11-14 04:00:00']);
        $this->csvRun(now()->subDays(3));

        $html = $this->page();

        $this->assertMatchesRegularExpression('/<dt>Last sync<\/dt>\s*<dd class="import-detail-value">\s*14 Nov 2026, 05:00/', $html);
        $this->assertStringContainsString('<dt>Last import</dt>', $html);
        $this->assertStringContainsString('Legend, weekly trend, aerobic efficiency and sync detail', $html);
    }

    /**
     * @return array<string, array{GarminSyncFailure, string}>
     */
    public static function failures(): array
    {
        return [
            'key rejected' => [GarminSyncFailure::KeyRejected, 'Garmin key rejected — renew it'],
            'unreachable' => [GarminSyncFailure::Unreachable, 'Garmin unreachable'],
            'unexpected' => [GarminSyncFailure::Unexpected, 'Garmin sync failed'],
        ];
    }

    #[DataProvider('failures')]
    public function test_the_newest_finished_attempt_failing_shows_the_warning_as_an_alert(GarminSyncFailure $cause, string $warning): void
    {
        $this->csvRun(now()->subDays(3));
        GarminSync::factory()->failed($cause)->create(['finished_at' => now()->subMinutes(5)]);

        $html = $this->page();

        $this->assertMatchesRegularExpression('/<div role="alert"[^>]*>.*?'.preg_quote($warning, '/').'/s', $html);
        $this->assertStringContainsString($cause->advice(), html_entity_decode($html));
    }

    #[DataProvider('failures')]
    public function test_the_warning_also_shows_on_the_empty_state(GarminSyncFailure $cause, string $warning): void
    {
        GarminSync::factory()->failed($cause)->create(['finished_at' => now()->subMinutes(5)]);

        $response = $this->get('/');

        $response->assertViewIs('dashboard-empty');
        $response->assertSee($warning);
        $response->assertSee('role="alert"', escape: false);
    }

    public function test_the_next_successful_sync_removes_the_warning(): void
    {
        $this->csvRun(now()->subDays(3));
        GarminSync::factory()->failed()->create(['finished_at' => now()->subHours(3)]);
        $this->assertStringContainsString('role="alert"', $this->page());

        GarminSync::factory()->succeeded()->create(['finished_at' => now()->subHour()]);

        $html = $this->page();
        $this->assertStringNotContainsString('role="alert"', $html);
        $this->assertStringNotContainsString('Garmin unreachable', $html);
    }

    public function test_a_running_attempt_does_not_hide_an_earlier_failure(): void
    {
        $this->csvRun(now()->subDays(3));
        GarminSync::factory()->failed(GarminSyncFailure::KeyRejected)->create(['finished_at' => now()->subHours(3)]);
        GarminSync::factory()->running()->create();

        $this->assertStringContainsString('Garmin key rejected — renew it', $this->page());
    }

    public function test_a_failure_older_than_a_success_shows_nothing(): void
    {
        $this->csvRun(now()->subDays(3));
        GarminSync::factory()->succeeded()->create(['finished_at' => now()->subHours(3)]);
        GarminSync::factory()->failed()->create(['finished_at' => now()->subHours(5)]);

        $this->assertStringNotContainsString('role="alert"', $this->page());
    }

    public function test_with_no_key_nothing_of_the_sync_is_rendered_whatever_the_table_holds(): void
    {
        Config::set('services.garmin.api_key', '');
        $this->csvRun(now()->subDays(3));
        GarminSync::factory()->failed(GarminSyncFailure::KeyRejected)->create(['finished_at' => now()->subMinutes(5)]);
        GarminSync::factory()->succeeded()->create(['finished_at' => now()->subMinute()]);

        $html = $this->page();

        $this->assertStringNotContainsString('Sync now', $html);
        $this->assertStringNotContainsString('Last sync', $html);
        $this->assertStringNotContainsString('last sync', $html);
        $this->assertStringNotContainsString('role="alert"', $html);
        $this->assertStringNotContainsString('Garmin key rejected', $html);
        $this->assertStringContainsString('Last import 3 days ago', $html);
        $this->assertStringContainsString('Legend, weekly trend, aerobic efficiency and import detail', $html);
    }

    public function test_with_no_key_the_empty_state_is_exactly_as_before(): void
    {
        Config::set('services.garmin.api_key', '');

        $response = $this->get('/');

        $response->assertViewIs('dashboard-empty');
        $response->assertSee('No runs imported yet');
        $response->assertDontSee('Sync now');
        $response->assertDontSee('role="alert"', escape: false);
    }

    public function test_the_status_never_calls_garmin(): void
    {
        GarminSync::factory()->failed()->create(['finished_at' => now()->subMinute()]);

        $this->page();

        Http::assertNothingSent();
    }

    private function csvRun(\DateTimeInterface $updatedAt): Activity
    {
        return Activity::factory()->garminImported()->for($this->owner)->create([
            'start_date_local' => '2026-11-12 07:00:00',
            'activity_date' => '2026-11-12',
            'updated_at' => $updatedAt,
        ]);
    }

    private function page(): string
    {
        return (string) $this->get('/')->assertOk()->getContent();
    }
}
