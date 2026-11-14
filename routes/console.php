<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Once a day, early in the owner's own morning: the timezone is the one the dashboard
 * prints times in, so 05:00 is breakfast time and yesterday evening's run is already
 * there. Sync now covers the rest of the day. An instance with no GARMIN_API_KEY runs the
 * command all the same, and it calls nothing and records nothing. The host needs the
 * scheduler's cron entry for this to run at all (README).
 */
Schedule::command('garmin:sync --scheduled')
    ->dailyAt('05:00')
    ->timezone(config('heatmap.display_timezone'));
