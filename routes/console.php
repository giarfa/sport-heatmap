<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
 * Nothing is scheduled: the Garmin CSV import is the only way a run enters, and
 * it is the owner's own action, so no background job runs on this instance.
 */
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
