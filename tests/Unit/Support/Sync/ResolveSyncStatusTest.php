<?php

namespace Tests\Unit\Support\Sync;

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

        $successfulRun = SyncRun::factory()->create(['finished_at' => now()->subHours(10)]);
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
}
