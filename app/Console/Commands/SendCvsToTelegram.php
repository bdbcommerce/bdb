<?php

namespace App\Console\Commands;

use App\Models\JobApplication;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

/** Sends CVs still kept on the server (older ones, or failed uploads) to the admin Telegram chat and deletes them here. */
class SendCvsToTelegram extends Command
{
    protected $signature = 'careers:send-cvs';

    protected $description = 'Send CVs still stored on the server to the admin Telegram chat, then delete them from the server';

    public function handle(TelegramNotifier $notifier): int
    {
        if (! $notifier->enabled()) {
            $this->error('Telegram is not configured (TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID).');

            return self::FAILURE;
        }

        $sent = $failed = 0;
        // Only CVs older than 10 minutes: brand-new ones are still being sent right after the form.
        // No text fallback: the details were already posted when the CV arrived.
        JobApplication::whereNull('cv_file_id')->whereNotNull('cv_path')
            ->where('created_at', '<', now()->subMinutes(10))
            ->lazyById() // by id, not offset: sent rows drop out of this query
            ->each(function (JobApplication $application) use ($notifier, &$sent, &$failed) {
                $application->sendCvToTelegram($notifier, textFallback: false) ? $sent++ : $failed++;
            });

        $this->info("Sent: {$sent}. Failed (kept on the server): {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
