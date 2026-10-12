<?php

namespace Tests\Unit\Support\Sync;

use App\Enums\SyncHealth;
use App\Models\Activity;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Sync\ResolveSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveSyncStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00');
    }

    public function test_it_picks_the_most_recent_run_and_the_most_recent_successful_run_independently(): void
    {
        $athlete = $this->allowlistedAthlete();

        $successfulRun = SyncRun::factory()->create([
            'started_at' => now()->subHours(10)->subMinute(),
            'finished_at' => now()->subHours(10),
        ]);
        $failedRun = SyncRun::factory()->failed()->create([
            'started_at' => now()->subHours(2)->subMinutes(1),
            'finished_at' => now()->subHours(2),
        ]);

        $status = (new ResolveSyncStatus)($athlete, HeatmapWindow::current(), now());

        $this->assertTrue($status->lastRun?->is($failedRun));
        $this->assertTrue($status->lastSuccessfulRun?->is($successfulRun));
    }

    public function test_it_counts_only_this_athletes_activities_inside_the_window(): void
    {
        $athlete = $this->allowlistedAthlete();
        $otherAthlete = User::factory()->stravaConnected()->create();

        $window = HeatmapWindow::current();

        Activity::factory()->for($athlete)->onDate($window->endsOn->subDays(2))->create();
        Activity::factory()->for($athlete)->onDate($window->endsOn->subDays(4))->create();

        // Outside the window: must not be counted.
        Activity::factory()->for($athlete)->onDate($window->startsOn->subDays(1))->create();

        // Another athlete's activity inside the window: must not be counted.
        Activity::factory()->for($otherAthlete)->onDate($window->endsOn->subDays(2))->create();

        $status = (new ResolveSyncStatus)($athlete, $window, now());

        $this->assertSame(2, $status->runsInWindow);
    }

    public function test_a_csv_only_athlete_ignores_a_failed_and_a_stale_sync_history(): void
    {
        $athlete = User::factory()->csvOnly()->create();
        Activity::factory()->garminImported()->for($athlete)->create([
            'activity_date' => now()->subDays(3)->toDateString(),
            'updated_at' => now()->subHours(2),
        ]);
        Activity::factory()->garminImported()->for($athlete)->create([
            'activity_date' => now()->subDays(5)->toDateString(),
            'updated_at' => now()->subDays(4),
        ]);
        SyncRun::factory()->stale()->create();
        SyncRun::factory()->failed()->create();

        $status = app(ResolveSyncStatus::class)($athlete, HeatmapWindow::current(), now());

        $this->assertSame(SyncHealth::CsvOnly, $status->health);
        $this->assertFalse($status->needsWarning());
        $this->assertSame('Imported 2 hours ago', $status->chipLabel());
        $this->assertSame(2, $status->runsInWindow);
    }
}
