<?php

namespace App\Services;

use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tells admins in Telegram when the AI assistant cannot answer (missing key, Gemini errors,
 * timeouts, unusable replies). Customers get the fallback reply meanwhile. Each kind of
 * problem is reported at most once per THROTTLE_MINUTES, so an outage doesn't flood the chat.
 * Goes to TELEGRAM_ALERT_CHAT_ID, or the sales TELEGRAM_CHAT_ID when that is empty.
 */
class AiErrorAlerts
{
    public const THROTTLE_MINUTES = 15;

    public function __construct(private TelegramApi $telegram) {}

    public function chatId(): ?string
    {
        return config('services.telegram.alert_chat_id') ?: config('services.telegram.chat_id') ?: null;
    }

    /**
     * @param  string  $reason  short, stable description; used to throttle repeats
     * @param  string|null  $details  e.g. the API's error message (secrets are removed)
     */
    public function report(string $reason, ?string $details = null, ?string $channel = null): void
    {
        $chatId = $this->chatId();
        if (! $chatId || ! $this->telegram->configured()) {
            return;
        }

        // Cache::add only succeeds for the first report in the window.
        if (! Cache::add('ai-alert:'.md5($reason), true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        $text = $this->message($reason, $details, $channel);

        // Sent right away (at most once per THROTTLE_MINUTES, so the delay is rare). Not deferred:
        // the Telegram bot already runs deferred, and a nested defer would never run.
        try {
            $this->telegram->sendMessage($chatId, $text, ['parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
        } catch (Throwable) {
            // TelegramApi already logs; an alert must never break anything.
        }
    }

    public function message(string $reason, ?string $details, ?string $channel): string
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = [
            '⚠️ <b>AI assistant error</b>'.($channel ? ' ('.$e($channel).')' : ''),
            '',
            '<b>Problem:</b> '.$e($reason),
        ];
        if ($details = trim((string) $details)) {
            $lines[] = '<b>Details:</b> <code>'.$e(mb_strimwidth($this->withoutSecrets($details), 0, 600, '…')).'</code>';
        }
        $lines[] = '<b>Model:</b> '.$e(config('services.gemini.model'));
        $lines[] = '<b>Time:</b> '.now()->format('d.m.Y H:i');
        $lines[] = '';
        $lines[] = 'Customers get the "leave your phone number" reply until this is fixed. Check with: <code>php artisan ai:check</code>';
        $lines[] = '<i>Repeats of this problem are muted for '.self::THROTTLE_MINUTES.' minutes.</i>';

        return implode("\n", $lines);
    }

    private function withoutSecrets(string $text): string
    {
        foreach (array_filter([config('services.gemini.key'), config('services.telegram.bot_token')]) as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return $text;
    }
}
