<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SetLocale;
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
        // Requests for other Host names are refused in production, so generated links (alerts,
        // canonical URLs) can't be pointed at a foreign host. Not applied in local / tests.
        $middleware->trustHosts();

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'set-locale' => SetLocale::class,
        ]);

        // Public, anonymous, rate-limited JSON endpoints. Without this, a chat tab left
        // open past the session lifetime fails with 419 and the visitor's lead is lost.
        // The Telegram webhook is authenticated by its own secret header instead.
        $middleware->validateCsrfTokens(except: ['chat/*', 'telegram/webhook', 'mcp']);

        // Telegram updates are handled exactly as Telegram sent them.
        $isTelegramWebhook = fn (Request $request) => $request->is('telegram/webhook');
        $middleware->trimStrings(except: [$isTelegramWebhook]);
        $middleware->convertEmptyStringsToNull(except: [$isTelegramWebhook]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API keys typed into admin forms must never be flashed into the session (stored in the DB).
        $exceptions->dontFlash(['api_key']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'chat/*', 'telegram/*'),
        );
    })->create();
