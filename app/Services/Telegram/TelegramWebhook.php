<?php

namespace App\Services\Telegram;

/**
 * Connects the bot to this site's webhook. Shared by the
 * telegram:webhook command and the admin Telegram settings page.
 */
class TelegramWebhook
{
    public const SECRET_PATTERN = '/^[A-Za-z0-9_-]{16,256}$/';

    public function __construct(private TelegramApi $api) {}

    public function url(): string
    {
        return route('telegram.webhook');
    }

    /** @return string|null an error message, or null when the webhook was set */
    public function connect(): ?string
    {
        if (! $this->api->configured()) {
            return 'Set TELEGRAM_BOT_TOKEN in .env first.';
        }

        $secret = (string) config('services.telegram.webhook_secret');
        if (! preg_match(self::SECRET_PATTERN, $secret)) {
            return 'Set TELEGRAM_WEBHOOK_SECRET in .env: 16+ characters, letters, digits, _ or -.';
        }

        $url = $this->url();
        if (! str_starts_with($url, 'https://')) {
            return "Telegram requires HTTPS, but the webhook URL is {$url}. Set APP_URL to the public https:// address.";
        }

        $ok = $this->api->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => true,
        ]) !== null;

        return $ok ? null : 'Telegram rejected the request — see storage/logs/laravel.log.';
    }

    public function disconnect(): bool
    {
        return $this->api->call('deleteWebhook', ['drop_pending_updates' => true]) !== null;
    }

    /**
     * Bot and webhook state, or null when Telegram could not be reached (or the token is wrong).
     *
     * @return array{bot: string, url: string|null, connected: bool, pending: int, last_error: string|null}|null
     */
    public function status(): ?array
    {
        $bot = $this->api->call('getMe');
        $info = $bot === null ? null : $this->api->call('getWebhookInfo');
        if ($info === null) {
            return null;
        }

        $url = ($info['url'] ?? '') ?: null;

        return [
            'bot' => '@'.($bot['username'] ?? '?'),
            'url' => $url,
            'connected' => $url === $this->url(),
            'pending' => (int) ($info['pending_update_count'] ?? 0),
            'last_error' => isset($info['last_error_message'])
                ? date('Y-m-d H:i', $info['last_error_date'] ?? time()).' — '.$info['last_error_message']
                : null,
        ];
    }
}
