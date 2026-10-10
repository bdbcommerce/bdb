<?php

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
            'admin'      => \App\Http\Middleware\AdminMiddleware::class,
            'set-locale' => \App\Http\Middleware\SetLocale::class,
        ]);

        // Public, anonymous, rate-limited JSON endpoints. Without this, a chat tab left
        // open past the session lifetime fails with 419 and the visitor's lead is lost.
        // The Telegram webhook is authenticated by its own secret header instead.
        $middleware->validateCsrfTokens(except: ['chat/*', 'telegram/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'chat/*', 'telegram/*'),
        );
    })->create();
