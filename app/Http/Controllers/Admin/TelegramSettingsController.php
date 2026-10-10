<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramWebhook;
use App\Services\TelegramNotifier;
use Illuminate\Http\Request;

/**
 * Telegram bot status and webhook connection. Credentials live in .env only.
 */
class TelegramSettingsController extends Controller
{
    /** .env variable => config key */
    public const ENV = [
        'TELEGRAM_BOT_TOKEN' => 'services.telegram.bot_token',
        'TELEGRAM_CHAT_ID' => 'services.telegram.chat_id',
        'TELEGRAM_WEBHOOK_SECRET' => 'services.telegram.webhook_secret',
    ];

    public function edit(Request $request, TelegramWebhook $webhook)
    {
        return view('admin.telegram.edit', [
            'env' => array_map(fn (string $key) => filled(config($key)), self::ENV),
            'webhookUrl' => $webhook->url(),
            'status' => $request->boolean('check') ? ($webhook->status() ?? false) : null,
            'users' => [
                'registered' => TelegramUser::registered()->count(),
                'pending' => TelegramUser::whereNull('registered_at')->count(),
            ],
        ]);
    }

    public function connect(TelegramWebhook $webhook)
    {
        $error = $webhook->connect();

        return redirect()->route('admin.telegram.edit')->with(
            $error === null ? 'success' : 'telegram_error',
            $error ?? 'Webhook connected: the bot now answers customers at '.$webhook->url(),
        );
    }

    public function disconnect(TelegramWebhook $webhook)
    {
        return redirect()->route('admin.telegram.edit')->with(...($webhook->disconnect()
            ? ['success', 'Webhook removed. The bot no longer answers customers.']
            : ['telegram_error', 'Telegram request failed — check TELEGRAM_BOT_TOKEN and storage/logs/laravel.log.']));
    }

    public function test(TelegramNotifier $notifier)
    {
        if (! $notifier->enabled()) {
            return redirect()->route('admin.telegram.edit')
                ->with('telegram_error', 'Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in .env first.');
        }

        $ok = $notifier->send('<b>Test message from the BDB admin panel.</b>'."\n".'New chat leads will be posted here.');

        return redirect()->route('admin.telegram.edit')->with(...($ok
            ? ['success', 'Test message sent to the sales chat.']
            : ['telegram_error', 'Telegram did not accept the message. Check TELEGRAM_CHAT_ID and that the bot was added to that chat.']));
    }
}
