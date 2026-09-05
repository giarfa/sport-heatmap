<?php

/*
 * Strava integration configuration.
 *
 * Never read these values with env() outside this file: env() returns null once
 * config:cache has run. Resolve them through config('strava.*') instead.
 */

/*
 * The allowlist fails closed. env() coerces values like "true" or "null" into PHP
 * scalars, and casting those to string would yield entries such as "1" that
 * authorize an athlete nobody intended — so a non-string value is discarded
 * outright, and only all-digit entries survive the filter.
 */
$rawAllowedAthleteIds = env('STRAVA_ALLOWED_ATHLETE_IDS', '');

$allowedAthleteIds = array_values(array_filter(
    array_map('trim', explode(',', is_string($rawAllowedAthleteIds) ? $rawAllowedAthleteIds : '')),
    static fn (string $athleteId): bool => $athleteId !== '' && ctype_digit($athleteId),
));

/**
 * @var array{
 *     client_id: string|null,
 *     client_secret: string|null,
 *     redirect_uri: string|null,
 *     allowed_athlete_ids: array<int, string>,
 *     sync_hour: int,
 *     sync_timezone: string,
 * } $config
 */
$config = [

    /*
    |--------------------------------------------------------------------------
    | OAuth application credentials
    |--------------------------------------------------------------------------
    |
    | Issued by the Strava developer dashboard. The redirect URI must match the
    | authorization callback domain registered there — in development that is
    | the Laravel Herd HTTPS .test URL.
    |
    */

    'client_id' => env('STRAVA_CLIENT_ID'),

    'client_secret' => env('STRAVA_CLIENT_SECRET'),

    'redirect_uri' => env('STRAVA_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | Athlete allowlist
    |--------------------------------------------------------------------------
    |
    | Comma-separated Strava athlete ids permitted to connect. An empty value
    | yields an empty array — never [''] — so a blank allowlist can never match
    | an athlete by accident.
    |
    */

    'allowed_athlete_ids' => $allowedAthleteIds,

    /*
    |--------------------------------------------------------------------------
    | Daily sync schedule
    |--------------------------------------------------------------------------
    |
    | Local hour (0-23) at which the daily activity sync runs, and the timezone
    | that hour is expressed in. This is the only timezone-sensitive setting in
    | the product.
    |
    */

    'sync_hour' => (int) env('STRAVA_SYNC_HOUR', 3),

    'sync_timezone' => (string) env('STRAVA_SYNC_TIMEZONE', 'Europe/Rome'),

];

return $config;
