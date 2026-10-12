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
     * The OAuth entry points are the only unauthenticated routes in the product,
     * so they are the only thing worth brute-forcing. Ten attempts a minute is
     * far more than a real athlete ever needs. The Garmin upload is limited per
     * owner.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('strava-oauth', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        /*
         * Each upload parses up to 5 MB in the request; ten a minute is far
         * beyond what an owner re-trying an export needs, and bounds the cost
         * of a stolen session hammering the parser.
         */
        RateLimiter::for('garmin-import', fn (Request $request): Limit => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
