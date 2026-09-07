<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * withoutOverlapping() because a run that outlives its window would otherwise
 * overlap the next day's, and two concurrent reconciles of the same window
 * race on the delete pass.
 */
Schedule::command('strava:sync')
    ->dailyAt(sprintf('%02d:00', (int) config('strava.sync_hour')))
    ->timezone((string) config('strava.sync_timezone'))
    ->withoutOverlapping();
