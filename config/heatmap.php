<?php

/*
 * Heatmap grid configuration.
 *
 * Never read these values with env() outside this file: env() returns null once
 * config:cache has run. Resolve them through config('heatmap.*') instead.
 */

/**
 * @var array{
 *     window_weeks: int,
 *     navigation_step_weeks: int,
 *     grid_cache_ttl: int,
 *     display_timezone: string,
 * } $config
 */
$displayTimezone = env('HEATMAP_DISPLAY_TIMEZONE');

$config = [

    /*
    |--------------------------------------------------------------------------
    | Window size
    |--------------------------------------------------------------------------
    |
    | The grid is 16 rows by product definition (FR-004): this is a literal, not
    | an env() lookup, because an environment file must not be able to change the
    | span the percentile scale calibrates against (US-005). US-003, US-004 and
    | US-005 all read this same value through HeatmapWindow.
    |
    */

    'window_weeks' => 16,

    /*
    |--------------------------------------------------------------------------
    | Navigation step
    |--------------------------------------------------------------------------
    |
    | Previous and Next move the window by this many weeks (FR-019). It is a
    | literal for the same reason window_weeks is: the step is part of the
    | product definition, and a deployment file must not change how far a shared
    | link's neighbours are.
    |
    */

    'navigation_step_weeks' => 4,

    /*
    |--------------------------------------------------------------------------
    | Grid cache TTL
    |--------------------------------------------------------------------------
    |
    | The cache key already carries the calendar day (HeatmapGridCache), so this
    | TTL only bounds how long a stale entry can outlive a failed cache flush; it
    | is a literal rather than an env() lookup for the same reason window_weeks
    | is — a deployment file must not be able to widen how long a stale grid can
    | be served.
    |
    */

    'grid_cache_ttl' => 86_400,

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    |
    | The timezone the import detail card prints its absolute "Last import"
    | stamp in (FR-007). Timestamps are stored in UTC; this is a deployment
    | choice (the owner's wall clock), so unlike the window constants above it
    | is read from the environment. A blank or unknown value falls back to
    | Europe/Rome rather than reaching the page, where it would be a 500 for
    | every owner with a run.
    |
    */

    'display_timezone' => is_string($displayTimezone) && in_array($displayTimezone, DateTimeZone::listIdentifiers(), true)
        ? $displayTimezone
        : 'Europe/Rome',

];

return $config;
