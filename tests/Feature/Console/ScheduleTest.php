<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_the_garmin_sync_is_the_only_scheduled_task_and_runs_once_a_day(): void
    {
        $events = $this->app->make(Schedule::class)->events();

        $this->assertCount(1, $events);
        $this->assertStringContainsString('garmin:sync --scheduled', $events[0]->command);
        $this->assertSame('0 5 * * *', $this->expressionIn($events[0]));
    }

    public function test_the_garmin_sync_is_listed_by_the_scheduler(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('garmin:sync --scheduled')->assertSuccessful();
    }

    public function test_the_strava_sync_is_not_an_artisan_command(): void
    {
        $this->assertArrayNotHasKey('strava:sync', Artisan::all());
    }

    /**
     * The cron expression as the owner's wall clock reads it: the event is timed in
     * the display timezone, so 05:00 there is the hour printed.
     */
    private function expressionIn(Event $event): string
    {
        $this->assertSame(config('heatmap.display_timezone'), $event->timezone);

        return $event->expression;
    }
}
