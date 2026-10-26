<?php

namespace Tests\Unit;

use App\Enums\SportType;
use App\Enums\SyncRunStatus;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The heatmap is a running heatmap: rides, swims and walks are ignored at
     * import time, so this list is the product's whole sport vocabulary (FR-003).
     */
    public function test_the_sport_type_enum_covers_exactly_the_running_types(): void
    {
        $this->assertSame(['Run', 'TrailRun', 'VirtualRun'], SportType::values());
    }

    /**
     * FR-007 branches on these three states to render the sync-status indicator.
     */
    public function test_the_sync_run_status_enum_covers_every_outcome(): void
    {
        $this->assertSame(
            ['running', 'succeeded', 'failed'],
            array_column(SyncRunStatus::cases(), 'value'),
        );
    }
}
