<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Services\AiErrorAlerts;
use App\Services\ChatAssistant;
use Illuminate\Console\Command;

/**
 * Why can't the AI answer? Makes one tiny real call to each active model (Admin → AI settings,
 * or Gemini from .env when none are set up) and prints the exact error.
 */
class AiCheckCommand extends Command
{
    protected $signature = 'ai:check {--alert : also send a test alert to the Telegram alerts chat}';

    protected $description = 'Check that the AI models work, and show why they fail if not';

    public function handle(ChatAssistant $assistant, AiErrorAlerts $alerts): int
    {
        $this->line('Alerts:  '.($alerts->chatId() && filled(config('services.telegram.bot_token'))
            ? 'Telegram chat '.$alerts->chatId()
            : '<comment>off</comment> (set TELEGRAM_BOT_TOKEN and TELEGRAM_ALERT_CHAT_ID or TELEGRAM_CHAT_ID)'));

        if ($this->option('alert')) {
            $this->line('Sending a test alert…');
            $alerts->report('Test alert from php artisan ai:check ('.now()->format('H:i:s').')', 'If you see this, AI error alerts reach this chat.');
        }

        $models = AiModel::where('is_active', true)->orderBy('priority')->orderBy('id')->get();
        if ($models->isEmpty()) {
            $this->line('No active models in Admin → AI settings; using Gemini from .env.');
            $models = collect([AiModel::fromEnv()]);
        }

        $working = 0;
        foreach ($models as $model) {
            $this->newLine();
            $this->line("<options=bold>{$model->name}</> ({$model->model})");
            $this->line('API key: '.(filled($model->key()) ? 'set' : '<error>not set</error>'));

            $error = $assistant->testModel($model);
            if ($error === null) {
                $working++;
                $this->info('OK: answers.');
            } else {
                $this->error('Fails: '.$error);
                $this->line($this->hint($error));
            }
        }

        $this->newLine();
        if ($working === 0) {
            $this->error('The AI cannot answer: no model works.');

            return self::FAILURE;
        }

        $this->info("{$working} of {$models->count()} ".str('model')->plural($models->count()).' work.');

        return self::SUCCESS;
    }

    private function hint(string $error): string
    {
        return match (true) {
            str_contains($error, 'not set'), str_contains($error, 'No API key') => 'Add the key in Admin → AI settings (each model shows how to get a free one).',
            str_contains($error, 'API key not valid'), str_contains($error, 'API_KEY_INVALID'), str_contains($error, '401') => 'The key is wrong or was deleted. Create a new one at the provider and save it in AI settings.',
            str_contains($error, '404'), str_contains($error, 'not found') => 'The model name is wrong or retired. Check the provider\'s model list and fix it in AI settings.',
            str_contains($error, '429') => 'Quota or rate limit reached. Wait, enable billing, or add another free model as a backup.',
            str_contains($error, 'location'), str_contains($error, 'FAILED_PRECONDITION') => 'Not available from this server\'s location. Use another provider (e.g. Groq or OpenRouter).',
            str_contains($error, 'timeout'), str_contains($error, 'network') => 'The server cannot reach the provider. Check the firewall / outgoing HTTPS.',
            default => 'See storage/logs/laravel.log for the full response.',
        };
    }
}
