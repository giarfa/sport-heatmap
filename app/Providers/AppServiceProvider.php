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
     * The Garmin upload, review and confirm are limited per signed-in owner.
     */
    private function registerRateLimiters(): void
    {
        /*
         * Each upload parses up to 5 MB in the request, and each review plans
         * the pending rows against the stored runs; ten a minute is far beyond
         * what re-trying an export needs, and bounds the cost of
         * anyone hammering the parser or the review. Keyed by user id: the routes
         * sit behind `auth`, and a session key would let a client reset the limit
         * by dropping its cookie.
         */
        RateLimiter::for('garmin-import', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
