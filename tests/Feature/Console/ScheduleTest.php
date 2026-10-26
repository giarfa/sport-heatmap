<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_nothing_is_scheduled(): void
    {
        $this->assertSame([], $this->app->make(Schedule::class)->events());
    }

    public function test_the_strava_sync_is_not_an_artisan_command(): void
    {
        $this->assertArrayNotHasKey('strava:sync', Artisan::all());
    }
}
