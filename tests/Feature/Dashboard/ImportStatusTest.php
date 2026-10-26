<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The rendered dashboard status (FR-007): "last import N ago" read from the
 * newest imported run, with no warning state of any kind.
 */
class ImportStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00'); // Saturday
    }

    public function test_the_chip_and_footer_read_last_import_from_the_newest_imported_run(): void
    {
        $owner = $this->signInOwner();
        $this->importedRun($owner, '2026-09-03', now()->subDays(3));

        $response = $this->get('/');

        $response->assertOk()->assertViewIs('dashboard');
        $response->assertSee('Last import 3 days ago');
        $response->assertSee('· last import 3 days ago', escape: false);
        $response->assertDontSee('role="alert"', escape: false);
    }

    public function test_a_reimport_that_writes_nothing_does_not_move_the_status(): void
    {
        $owner = $this->signInOwner();
        $run = $this->importedRun($owner, '2026-09-03', now()->subDays(3));

        $before = $this->get('/')->getContent();

        // The no-op re-import touches no row: the same row stays the newest.
        $this->travelTo(now()->addHour());
        $after = $this->get('/')->getContent();

        $this->assertStringContainsString('Last import 3 days ago', (string) $before);
        $this->assertStringContainsString('Last import 3 days ago', (string) $after);
        $this->assertSame($run->updated_at?->toDateTimeString(), Activity::query()->max('updated_at'));
    }

    public function test_the_detail_card_prints_the_absolute_stamp_in_the_display_timezone(): void
    {
        Config::set('heatmap.display_timezone', 'Europe/Rome');
        $owner = $this->signInOwner();
        $this->importedRun($owner, '2026-09-03', Carbon::parse('2026-09-04 22:30:00', 'UTC'));

        $response = $this->get('/');

        $response->assertSee('>Import</h3>', escape: false);
        $response->assertSee('5 Sep 2026, 00:30');
        $response->assertSee('Runs in window');
    }

    public function test_no_state_renders_a_warning(): void
    {
        $owner = $this->signInOwner();
        $this->importedRun($owner, '2026-09-03', now()->subDays(40));

        $html = $this->get('/')->getContent() ?: '';

        $this->assertStringNotContainsString('role="alert"', $html);
        $this->assertStringNotContainsString('Reconnect', $html);
        $this->assertStringNotContainsString('Sync', $html);
    }

    public function test_viewing_the_dashboard_writes_nothing(): void
    {
        $owner = $this->signInOwner();
        $this->importedRun($owner, '2026-09-03', now()->subDays(3));
        $counts = [DB::table('users')->count(), DB::table('activities')->count()];
        $updatedAt = Activity::query()->max('updated_at');

        $this->get('/')->assertOk();

        $this->assertSame($counts, [DB::table('users')->count(), DB::table('activities')->count()]);
        $this->assertSame($updatedAt, Activity::query()->max('updated_at'));
        Http::assertNothingSent();
    }

    private function importedRun(User $owner, string $date, Carbon $updatedAt): Activity
    {
        return Activity::factory()->garminImported()->for($owner)->create([
            'start_date_local' => "{$date} 07:00:00",
            'activity_date' => $date,
            'distance_meters' => 12_345,
            'updated_at' => $updatedAt,
        ]);
    }
}
