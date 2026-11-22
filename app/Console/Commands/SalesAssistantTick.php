<?php

namespace App\Console\Commands;

use App\Models\ChatConversation;
use App\Models\SalesAssistantSetting;
use App\Services\SalesAssistant\SalesAssistant;
use Illuminate\Console\Command;

/**
 * Every minute: answers customers whose wait is over — in working hours a manager gets `wait_minutes` first
 * (Premium-account chats, and bot chats where a manager was talking). Only the latest session per chat, last 24 h.
 */
class SalesAssistantTick extends Command
{
    protected $signature = 'sales:assistant-tick';

    protected $description = 'Sales assistant: answer customers no manager answered in time';

    public function handle(SalesAssistant $assistant): int
    {
        $settings = SalesAssistantSetting::current();
        if ($settings->bot_mode !== 'on' && $settings->business_mode !== 'on') {
            return self::SUCCESS;
        }
        $answered = 0;
        ChatConversation::whereIn('channel', ['telegram', 'business'])->where('updated_at', '>=', now()->subDay())
            ->orderBy('id')->get()
            ->groupBy(fn (ChatConversation $c) => $c->channel.'|'.$c->external_id.'|'.$c->business_connection_id)
            ->each(function ($sessions) use ($assistant, $settings, &$answered) {
                $latest = $sessions->last();
                if (SalesAssistant::decision($latest, $settings) === 'now' && $assistant->respond($latest)) {
                    $answered++;
                }
            });
        $this->info("Answered {$answered} conversations.");

        return self::SUCCESS;
    }
}
