<?php

namespace Tests\Unit\Support\Garmin;

use App\Models\Activity;
use App\Models\User;
use App\Support\Garmin\ResolveImportStatus;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveImportStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-05 09:00:00');
    }

    public function test_it_reads_the_newest_updated_at_of_the_owners_imported_runs(): void
    {
        $owner = $this->signInOwner();
        Activity::factory()->garminImported()->for($owner)->create(['updated_at' => now()->subDays(5)]);
        $newest = Activity::factory()->garminImported()->for($owner)->create(['updated_at' => now()->subDays(2)]);

        $status = (new ResolveImportStatus)($owner, HeatmapWindow::current(now()));

        $this->assertSame($newest->updated_at?->toDateTimeString(), $status->lastImportedAt?->toDateTimeString());
    }

    public function test_it_has_no_last_import_when_no_run_is_imported(): void
    {
        $owner = $this->signInOwner();

        $status = (new ResolveImportStatus)($owner, HeatmapWindow::current(now()));

        $this->assertNull($status->lastImportedAt);
        $this->assertSame(0, $status->runsInWindow);
    }

    public function test_it_counts_only_this_owners_runs_inside_the_window(): void
    {
        $owner = $this->signInOwner();
        $someoneElse = User::factory()->create();
        Activity::factory()->garminImported()->for($owner)->create(['start_date_local' => '2026-09-03 07:00:00', 'activity_date' => '2026-09-03']);
        Activity::factory()->garminImported()->for($owner)->create(['start_date_local' => '2026-09-02 07:00:00', 'activity_date' => '2026-09-02']);
        Activity::factory()->garminImported()->for($owner)->create(['start_date_local' => '2025-01-02 07:00:00', 'activity_date' => '2025-01-02']);
        Activity::factory()->garminImported()->for($someoneElse)->create(['start_date_local' => '2026-09-03 07:00:00', 'activity_date' => '2026-09-03']);

        $status = (new ResolveImportStatus)($owner, HeatmapWindow::current(now()));

        $this->assertSame(2, $status->runsInWindow);
    }
}
