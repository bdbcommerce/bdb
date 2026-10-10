<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin Telegram Bot API client. Never throws: a Telegram outage must not
 * break lead capture or the webhook.
 */
class TelegramApi
{
    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /** @return array<string, mixed>|null the "result" field, or null on failure */
    public function call(string $method, array $params = []): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::timeout(10)->asJson()->post(
                'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method,
                $params,
            );
        } catch (Throwable $e) {
            // Connection errors quote the request URL, which contains the bot token.
            $error = str_replace((string) config('services.telegram.bot_token'), '***', $e->getMessage());
            Log::warning("Telegram {$method} failed", ['error' => $error]);

            return null;
        }

        if ($response->failed() || ! $response->json('ok')) {
            Log::warning("Telegram {$method} failed", ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        return (array) $response->json('result');
    }

    /**
     * Download a file a customer sent. Bots can only fetch files up to 20 MB.
     * The download URL contains the bot token, so it must never reach the browser.
     *
     * @return array{body: string, type: string|null, name: string}|null
     */
    public function downloadFile(string $fileId): ?array
    {
        $path = $this->call('getFile', ['file_id' => $fileId])['file_path'] ?? null;
        if (! $path) {
            return null;
        }

        try {
            $response = Http::timeout(30)->get('https://api.telegram.org/file/bot'.config('services.telegram.bot_token').'/'.$path);
        } catch (Throwable $e) {
            Log::warning('Telegram file download failed', [
                'error' => str_replace((string) config('services.telegram.bot_token'), '***', $e->getMessage()),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Telegram file download failed', ['status' => $response->status()]);

            return null;
        }

        return [
            'body' => $response->body(),
            'type' => $response->header('Content-Type') ?: null,
            'name' => basename($path),
        ];
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): bool
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $extra) !== null;
    }
}
