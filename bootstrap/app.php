<?php

use App\Http\Middleware\EnsureFreshStravaToken;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'strava.fresh' => EnsureFreshStravaToken::class,
        ]);

        // Global, not in the web group: a URL that matches no route is a 404
        // that never enters group middleware, and it must be unframeable too.
        // Prepended so it is the outermost: a response another global middleware
        // ends the request with (503 in maintenance, 413 for an oversized upload)
        // passes through it as well.
        $middleware->prepend(SetSecurityHeaders::class);

        // No route uses auth today (the Garmin import opened to guests in
        // US-011). Kept so a future auth route cannot throw on the missing
        // login route: the owner signs in through Strava from the home page.
        $middleware->redirectGuestsTo(fn (): string => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
