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
 * } $config
 */
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

];

return $config;
