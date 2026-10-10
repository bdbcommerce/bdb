<?php

namespace App\Services;

use App\Models\AiModel;
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
     * @param  string|null  $answeredBy  a backup model that answered instead (customers not affected)
     */
    public function report(string $reason, ?string $details = null, ?string $channel = null, ?string $answeredBy = null, ?string $model = null): void
    {
        $chatId = $this->chatId();
        if (! $chatId || ! $this->telegram->configured()) {
            return;
        }

        // Cache::add only succeeds for the first report in the window.
        $key = 'ai-alert:'.md5($reason);
        if (! Cache::add($key, true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        $text = $this->message($reason, $details, $channel, $answeredBy, $model);

        // Sent right away (at most once per THROTTLE_MINUTES, so the delay is rare). Not deferred:
        // the Telegram bot already runs deferred, and a nested defer would never run.
        // A short timeout keeps a slow Telegram from holding up the customer's answer. If it could not be sent,
        // un-mute this problem so the next failure tries again.
        try {
            $sent = $this->telegram->sendMessage($chatId, $text, ['parse_mode' => 'HTML', 'disable_web_page_preview' => true], timeout: 3);
        } catch (Throwable) {
            $sent = false;
        }
        if (! $sent) {
            Cache::forget($key);
        }
    }

    public function message(string $reason, ?string $details, ?string $channel, ?string $answeredBy = null, ?string $model = null): string
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = [
            ($answeredBy ? '⚠️ <b>AI model error, backup used</b>' : '⚠️ <b>AI assistant error</b>').($channel ? ' ('.$e($channel).')' : ''),
            '',
            '<b>Problem:</b> '.$e($reason),
        ];
        if ($details = trim((string) $details)) {
            $lines[] = '<b>Details:</b> <code>'.$e(mb_strimwidth(self::withoutSecrets($details), 0, 600, '…')).'</code>';
        }
        $lines[] = '<b>Model:</b> '.$e($model ?? config('services.gemini.model'));
        $lines[] = '<b>Time:</b> '.now()->format('d.m.Y H:i');
        $lines[] = '';
        $lines[] = $answeredBy
            ? 'The backup model <b>'.$e($answeredBy).'</b> answered, so customers are not affected. The failing model is paused for a while.'
            : 'Customers get the "leave your phone number" reply until this is fixed. Check with: <code>php artisan ai:check</code> or Admin → AI settings.';
        $lines[] = '<i>Repeats of this problem are muted for '.self::THROTTLE_MINUTES.' minutes.</i>';

        return implode("\n", $lines);
    }

    /** The text with every AI key (.env and AI settings) and the bot token replaced by ***. */
    public static function withoutSecrets(string $text, ?string ...$more): string
    {
        $secrets = [config('services.gemini.key'), config('services.telegram.bot_token'), ...$more];
        try {
            foreach (AiModel::whereNotNull('api_key')->get() as $model) {
                $secrets[] = $model->api_key;
            }
        } catch (Throwable) {
            // Table missing or a key that no longer decrypts: mask what we have.
        }

        foreach (array_unique(array_filter($secrets, fn ($s) => is_string($s) && strlen($s) >= 6)) as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return $text;
    }
}
