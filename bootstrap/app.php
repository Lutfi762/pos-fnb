<?php

use App\Http\Middleware\EnsureOutletSelected;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureScreenUnlocked;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);

        // The payment gateway cannot hold a CSRF token; its webhooks prove
        // themselves with a signature instead.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'outlet' => EnsureOutletSelected::class,
            'unlocked' => EnsureScreenUnlocked::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
