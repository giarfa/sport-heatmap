<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * The OAuth entry points are limited per IP: ten attempts a minute is far
     * more than a real athlete ever needs. The Garmin upload, review and confirm,
     * open to guests since US-011, are limited per signed-in owner, else per IP.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('strava-oauth', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        /*
         * Each upload parses up to 5 MB in the request, and each review plans
         * the pending rows against the stored runs; ten a minute is far beyond
         * what a visitor re-trying an export needs, and bounds the cost of
         * anyone hammering the parser or the review. The import is open to guests, so a
         * signed-out request is keyed by IP — keying it by session would let a
         * client reset the limit by dropping its cookie.
         */
        RateLimiter::for('garmin-import', fn (Request $request): Limit => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
