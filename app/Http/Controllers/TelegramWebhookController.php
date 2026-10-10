<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

/**
 * Receives Telegram bot updates. Telegram signs each request with the secret
 * given to setWebhook (see `php artisan telegram:webhook set`).
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBot $bot): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');

        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $update = $request->json()->all();

        // Telegram redelivers an update if we were slow; handle each one only once.
        if (isset($update['update_id']) && ! Cache::add('telegram-update:'.$update['update_id'], true, now()->addDay())) {
            return response()->json(['ok' => true]);
        }

        // Answer Telegram right away; the AI reply is sent after the response.
        defer(fn () => $bot->handle($update));

        return response()->json(['ok' => true]);
    }
}
