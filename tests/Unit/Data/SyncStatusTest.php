<?php

namespace Tests\Unit\Data;

use App\Data\SyncStatus;
use App\Enums\SyncHealth;
use App\Models\SyncRun;
use Tests\TestCase;

/**
 * Pins the state machine before any of it reaches a template: precedence
 * order, the 48-hour boundary from both sides, the skeleton condition, and
 * the copy each warning state prints.
 */
class SyncStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-05 09:00:00');
    }

    public function test_no_rows_is_never_synced_with_the_first_run_copy(): void
    {
        $status = SyncStatus::from(null, null, 0, now());

        $this->assertSame(SyncHealth::NeverSynced, $status->health);
        $this->assertTrue($status->needsWarning());
        $this->assertFalse($status->showsSkeleton());
        $this->assertSame('Never synced', $status->chipLabel());
        $this->assertSame('warn', $status->chipDotModifier());
        $this->assertSame('Waiting for the first sync', $status->warningTitle());
        $this->assertSame(
            'No sync has completed yet. The nightly job runs at 3:00 Europe/Rome.',
            $status->warningReason(),
        );
    }

    public function test_a_failed_last_run_wins_over_a_recent_success(): void
    {
        $failedRun = SyncRun::factory()->failed()->make([
            'started_at' => now()->subHours(2)->subMinutes(1),
            'finished_at' => now()->subHours(2),
            'error_message' => 'Strava API returned 401 Unauthorized: refresh token rejected.',
        ]);
        $successfulRun = SyncRun::factory()->make([
            'started_at' => now()->subHours(6)->subSeconds(20),
            'finished_at' => now()->subHours(6),
        ]);

        $status = SyncStatus::from($failedRun, $successfulRun, 12, now());

        $this->assertSame(SyncHealth::Failed, $status->health);
        $this->assertTrue($status->needsWarning());
        $this->assertSame('Sync failed 2 hours ago', $status->chipLabel());
        $this->assertSame('warn', $status->chipDotModifier());
        $this->assertSame('Last sync failed 2 hours ago', $status->warningTitle());
        $this->assertSame(
            'Strava API returned 401 Unauthorized: refresh token rejected. The map below is showing data from 5 Sep.',
            $status->warningReason(),
        );
    }

    public function test_a_failed_last_run_with_no_success_ever_says_the_map_is_empty(): void
    {
        $failedRun = SyncRun::factory()->failed()->make([
            'finished_at' => now()->subHours(2),
            'error_message' => 'Connection to www.strava.com timed out after 30 seconds.',
        ]);

        $status = SyncStatus::from($failedRun, null, 0, now());

        $this->assertSame(SyncHealth::Failed, $status->health);
        $this->assertSame(
            'Connection to www.strava.com timed out after 30 seconds. No sync has ever completed, so the map below is empty.',
            $status->warningReason(),
        );
    }

    public function test_a_success_exactly_48_hours_old_is_still_healthy(): void
    {
        $successfulRun = SyncRun::factory()->make(['finished_at' => now()->subHours(48)]);

        $status = SyncStatus::from($successfulRun, $successfulRun, 12, now());

        $this->assertSame(SyncHealth::Healthy, $status->health);
        $this->assertFalse($status->needsWarning());
    }

    public function test_a_success_48_hours_and_one_second_old_is_stale(): void
    {
        $successfulRun = SyncRun::factory()->make(['finished_at' => now()->subHours(48)->subSecond()]);

        $status = SyncStatus::from($successfulRun, $successfulRun, 12, now());

        $this->assertSame(SyncHealth::Stale, $status->health);
        $this->assertTrue($status->needsWarning());
        $this->assertSame('warn', $status->chipDotModifier());
        $this->assertSame('Last successful sync was 2 days ago', $status->warningTitle());
        $this->assertSame(
            'The nightly sync has not completed since 3 Sep. The map below is showing data from then.',
            $status->warningReason(),
        );
    }

    public function test_a_success_six_hours_ago_with_no_failure_is_healthy_with_no_banner(): void
    {
        $successfulRun = SyncRun::factory()->make(['finished_at' => now()->subHours(6)]);

        $status = SyncStatus::from($successfulRun, $successfulRun, 12, now());

        $this->assertSame(SyncHealth::Healthy, $status->health);
        $this->assertFalse($status->needsWarning());
        $this->assertSame('Synced 6 hours ago', $status->chipLabel());
        $this->assertSame('ok', $status->chipDotModifier());
    }

    public function test_a_history_of_running_only_is_never_synced_and_shows_the_skeleton(): void
    {
        $runningRun = SyncRun::factory()->running()->make();

        $status = SyncStatus::from($runningRun, null, 0, now());

        $this->assertSame(SyncHealth::NeverSynced, $status->health);
        $this->assertTrue($status->isSyncing);
        $this->assertTrue($status->showsSkeleton());
    }

    public function test_a_run_in_flight_after_an_earlier_success_keeps_the_grid_up(): void
    {
        $runningRun = SyncRun::factory()->running()->make();
        $successfulRun = SyncRun::factory()->make(['finished_at' => now()->subHours(6)]);

        $status = SyncStatus::from($runningRun, $successfulRun, 12, now());

        $this->assertSame(SyncHealth::Healthy, $status->health);
        $this->assertTrue($status->isSyncing);
        $this->assertFalse($status->showsSkeleton());
    }
}
