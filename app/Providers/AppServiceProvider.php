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
     * far more than a real athlete ever needs.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('strava-oauth', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
    }
}
