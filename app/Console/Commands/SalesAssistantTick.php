<?php

namespace App\Console\Commands;

use App\Models\ChatConversation;
use App\Models\SalesAssistantSetting;
use App\Services\SalesAssistant\SalesAssistant;
use Illuminate\Console\Command;

/**
 * Every minute: answers customers whose wait is over — in working hours a manager gets `wait_minutes` first
 * (Premium-account chats, and bot chats where a manager was talking). Only the latest session per chat. Also: one
 * reminder to a customer who went silent mid-way, the unfinished request to the manager after close_after_hours,
 * and the agreed follow-up after follow_up_days.
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
        $answered = $reminded = $closed = $followedUp = 0;
        // Long enough to see a silence through to its close (the latest session per chat only).
        ChatConversation::whereIn('channel', ['telegram', 'business'])->where('updated_at', '>=', now()->subHours(max(24, $settings->close_after_hours + 24)))
            ->orderBy('id')->get()
            // One customer = one chat: older Premium-account sessions (channel business) and the bot chat together.
            ->groupBy(fn (ChatConversation $c) => $c->external_id ?? 'id:'.$c->id)
            ->each(function ($sessions) use ($assistant, $settings, &$answered, &$reminded, &$closed) {
                $latest = ChatConversation::newestOf($sessions);
                $decision = SalesAssistant::decision($latest, $settings);
                if (in_array($decision, ['now', 'closed'], true)) {
                    // A turn, the greeting, or the off-hours note (e.g. a Premium-account wait that ran past closing time).
                    $assistant->handle($latest) === 'answered' && $answered++;
                } elseif (SalesAssistant::nudgeDue($latest, $settings) !== null) {
                    match ($assistant->nudge($latest)) {
                        'reminded' => $reminded++,
                        'closed' => $closed++,
                        default => null,
                    };
                }
            });
        // Agreed follow-ups ("may we send an offer in 2 weeks?").
        ChatConversation::whereIn('channel', ['telegram', 'business'])->where('sales_state', 'like', '%follow_up_at%')
            ->where('updated_at', '>=', now()->subDays($settings->follow_up_days + 30))->orderBy('id')->get()
            ->each(function (ChatConversation $c) use ($assistant, $settings, &$followedUp) {
                if (SalesAssistant::followUpDue($c, $settings) && $assistant->followUp($c)) {
                    $followedUp++;
                }
            });
        $this->info("Answered {$answered}, reminded {$reminded}, closed {$closed} as unfinished, followed up {$followedUp}.");

        return self::SUCCESS;
    }
}
