<?php

namespace Tests\Unit\Models;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SyncRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_casts_its_attributes(): void
    {
        $syncRun = SyncRun::factory()->create();

        $this->assertInstanceOf(SyncRunStatus::class, $syncRun->status);
        $this->assertInstanceOf(Carbon::class, $syncRun->started_at);
        $this->assertInstanceOf(Carbon::class, $syncRun->finished_at);
        $this->assertGreaterThanOrEqual(0, $syncRun->activities_upserted);
        $this->assertGreaterThanOrEqual(0, $syncRun->activities_deleted);
    }

    public function test_the_default_state_is_a_recent_successful_run(): void
    {
        $syncRun = SyncRun::factory()->create();

        $this->assertSame(SyncRunStatus::Succeeded, $syncRun->status);
        $this->assertNull($syncRun->error_message);
        $this->assertTrue($syncRun->finished_at->isAfter($syncRun->started_at));
        $this->assertLessThan(48, $syncRun->started_at->diffInHours(now()));
    }

    public function test_the_failed_state_records_an_error_message(): void
    {
        $syncRun = SyncRun::factory()->failed()->create();

        $this->assertSame(SyncRunStatus::Failed, $syncRun->status);
        $this->assertNotNull($syncRun->error_message);
        $this->assertSame(0, $syncRun->activities_upserted);
        $this->assertSame(0, $syncRun->activities_deleted);
    }

    public function test_the_running_state_has_not_finished_yet(): void
    {
        $syncRun = SyncRun::factory()->running()->create();

        $this->assertSame(SyncRunStatus::Running, $syncRun->status);
        $this->assertNull($syncRun->finished_at);
    }

    /**
     * FR-007 warns the athlete when the last successful sync is older than 48 hours.
     */
    public function test_the_stale_state_is_older_than_the_warning_threshold(): void
    {
        $syncRun = SyncRun::factory()->stale()->create();

        $this->assertSame(SyncRunStatus::Succeeded, $syncRun->status);
        $this->assertGreaterThan(48, $syncRun->started_at->diffInHours(now()));
    }
}
