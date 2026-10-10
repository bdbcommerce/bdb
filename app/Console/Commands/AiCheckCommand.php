<?php

namespace App\Console\Commands;

use App\Services\AiErrorAlerts;
use App\Services\ChatAssistant;
use Illuminate\Console\Command;

/**
 * Why can't the AI answer? Makes one tiny real Gemini call and prints the exact error.
 */
class AiCheckCommand extends Command
{
    protected $signature = 'ai:check {--alert : also send a test alert to the Telegram alerts chat}';

    protected $description = 'Check that the AI assistant (Google Gemini) works, and show why it fails if not';

    public function handle(ChatAssistant $assistant, AiErrorAlerts $alerts): int
    {
        $this->line('Model:   '.config('services.gemini.model'));
        $this->line('API key: '.(filled(config('services.gemini.key')) ? 'set' : '<error>not set</error> (GEMINI_API_KEY)'));
        $this->line('Alerts:  '.($alerts->chatId() && filled(config('services.telegram.bot_token'))
            ? 'Telegram chat '.$alerts->chatId()
            : '<comment>off</comment> (set TELEGRAM_BOT_TOKEN and TELEGRAM_ALERT_CHAT_ID or TELEGRAM_CHAT_ID)'));

        if ($this->option('alert')) {
            $this->line('Sending a test alert…');
            $alerts->report('Test alert from php artisan ai:check ('.now()->format('H:i:s').')', 'If you see this, AI error alerts reach this chat.');
        }

        $this->line('Calling Gemini…');
        $error = $assistant->check();

        if ($error === null) {
            $this->info('OK: the AI assistant answers.');

            return self::SUCCESS;
        }

        $this->error('The AI cannot answer: '.$error);
        $this->line($this->hint($error));

        return self::FAILURE;
    }

    private function hint(string $error): string
    {
        return match (true) {
            str_contains($error, 'not set') => 'Add GEMINI_API_KEY to .env (free key: https://aistudio.google.com/apikey), then run php artisan config:clear.',
            str_contains($error, 'API key not valid'), str_contains($error, 'API_KEY_INVALID') => 'The key is wrong or was deleted. Create a new one at https://aistudio.google.com/apikey.',
            str_contains($error, '404'), str_contains($error, 'not found') => 'The model name is wrong or retired. Set GEMINI_MODEL in .env to a current model from https://ai.google.dev/gemini-api/docs/models.',
            str_contains($error, '429') => 'Quota or rate limit reached. Wait, or enable billing for the Google AI project.',
            str_contains($error, 'location'), str_contains($error, 'FAILED_PRECONDITION') => 'Gemini is not available from this server\'s location. Use a server/proxy in a supported region.',
            str_contains($error, 'timeout'), str_contains($error, 'network') => 'The server cannot reach generativelanguage.googleapis.com. Check the firewall / outgoing HTTPS.',
            default => 'See storage/logs/laravel.log for the full response.',
        };
    }
}
