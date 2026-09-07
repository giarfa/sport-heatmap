<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('STRAVA_SYNC_HOUR');
        unset($_ENV['STRAVA_SYNC_HOUR'], $_SERVER['STRAVA_SYNC_HOUR']);

        parent::tearDown();
    }

    public function test_the_sync_command_is_scheduled_daily_at_the_configured_hour_and_timezone(): void
    {
        $events = $this->events();

        $this->assertCount(1, $events);
        $this->assertStringContainsString('strava:sync', $events[0]->command);
        $this->assertSame('0 3 * * *', $events[0]->expression);
        $this->assertSame('Europe/Rome', $events[0]->timezone);
    }

    /**
     * The schedule is registered once, when the console kernel boots — proving
     * the configured hour is load-bearing means overriding it before that boot,
     * not with a post-boot Config::set().
     */
    public function test_the_schedule_follows_a_configured_hour(): void
    {
        putenv('STRAVA_SYNC_HOUR=5');
        $_ENV['STRAVA_SYNC_HOUR'] = '5';
        $_SERVER['STRAVA_SYNC_HOUR'] = '5';

        $this->refreshApplication();

        $events = $this->events();

        $this->assertSame('0 5 * * *', $events[0]->expression);
    }

    /**
     * @return array<int, Event>
     */
    private function events(): array
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        return array_values(array_filter(
            $schedule->events(),
            static fn (Event $event): bool => str_contains($event->command ?? '', 'strava:sync'),
        ));
    }
}
