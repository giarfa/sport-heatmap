<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Garmin Connect Personal API
    |--------------------------------------------------------------------------
    |
    | Only the key is a deployment choice. The host and the two paths are product
    | invariants, written as literals so no environment file can send the key to
    | another host. The access token lives in the `file` cache store and never in
    | the default one: CACHE_STORE=database would write it into SQLite.
    |
    */

    'garmin' => [
        'api_key' => env('GARMIN_API_KEY'),
        'base_url' => 'https://personal.connectapi.garmin.com',
        'token_path' => '/personalapitoken-service/personal-api/personal-api-key/token',
        'activities_path' => '/activitylist-service/activities/search/activities',
        'page_size' => 100,
        'resync_days' => 7,
        'token_cache_store' => 'file',
        'connect_timeout' => 5,
        'timeout' => 20,
    ],

];
