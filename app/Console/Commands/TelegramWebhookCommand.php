<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramApi;
use App\Services\Telegram\TelegramWebhook;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {action=info : set, info or delete}';

    protected $description = 'Connect the Telegram bot to this site (needs a public HTTPS APP_URL)';

    public function handle(TelegramApi $api, TelegramWebhook $webhook): int
    {
        if (! $api->configured()) {
            $this->error('Set TELEGRAM_BOT_TOKEN in .env first.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'set' => $this->set($webhook),
            'delete' => $this->report($webhook->disconnect(), 'Webhook removed.'),
            'info' => $this->info_($webhook),
            default => $this->invalid(),
        };
    }

    private function set(TelegramWebhook $webhook): int
    {
        $error = $webhook->connect();
        if ($error !== null) {
            $this->error($error);
            if (! preg_match(TelegramWebhook::SECRET_PATTERN, (string) config('services.telegram.webhook_secret'))) {
                $this->line('For example: TELEGRAM_WEBHOOK_SECRET='.bin2hex(random_bytes(24)));
            }

            return self::FAILURE;
        }

        $this->info('Webhook set to '.$webhook->url());

        return self::SUCCESS;
    }

    private function info_(TelegramWebhook $webhook): int
    {
        $status = $webhook->status();
        if ($status === null) {
            return $this->report(false, '');
        }

        $this->table(['', ''], [
            ['Bot', $status['bot']],
            ['Webhook URL', $status['url'] ?? '(not set)'],
            ['Pending updates', $status['pending']],
            ['Last error', $status['last_error'] ?? '—'],
        ]);

        return self::SUCCESS;
    }

    private function report(bool $ok, string $message): int
    {
        $ok ? $this->info($message) : $this->error('Telegram request failed — see storage/logs/laravel.log.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function invalid(): int
    {
        $this->error('Action must be set, info or delete.');

        return self::FAILURE;
    }
}
