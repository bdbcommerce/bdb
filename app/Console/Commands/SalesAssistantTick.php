<?php

namespace App\Console\Commands;

use App\Models\ChatConversation;
use App\Models\Order;
use App\Models\SalesAssistantSetting;
use App\Services\SalesAssistant\ExpertDesk;
use App\Services\SalesAssistant\LeadDesk;
use App\Services\SalesAssistant\MorningSummary;
use App\Services\SalesAssistant\OrderBook;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\Seo\SeoFiles;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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

    public function handle(SalesAssistant $assistant, ExpertDesk $desk): int
    {
        // Questions to experts: send the next ones, remind, pass to the main admin, deliver answers.
        $experts = rescue(fn () => $desk->tick(), null);
        $experts && $this->info("Experts: sent {$experts['sent']}, reminded {$experts['reminded']}, to the main admin {$experts['escalated']}, answers delivered {$experts['delivered']}.");
        // Requests nobody took ("I take it") in time: the main admin is reminded.
        ($leads = rescue(fn () => app(LeadDesk::class)->tick(), 0)) && $this->info("Requests not taken, reminded: {$leads}.");
        $settings = SalesAssistantSetting::current();
        // When the office opens: who wrote while it was closed, to the sales chat (once a working day).
        ($morning = rescue(fn () => app(MorningSummary::class)->run($settings), 0)) && $this->info("Morning summary: {$morning} chats.");
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
        // Draft orders the customer left: one reminder after draft_nudge_days (working hours).
        $nudged = 0;
        if ($settings->draftNudgeDays() > 0 && $settings->isWorkingTime()) {
            Order::where('status', 'draft')->whereNull('nudged_at')->where('updated_at', '<=', now()->subDays($settings->draftNudgeDays()))
                ->orderBy('id')->limit(20)->get()->each(function (Order $draft) use ($assistant, &$nudged) {
                    rescue(fn () => $assistant->draftNudge($draft), false) && $nudged++;
                });
        }
        // Reorder hints to the sales chat, once a day in working hours.
        $hints = 0;
        if ($settings->reorder_hints && $settings->isWorkingTime() && Cache::add('reorder-hints:'.now()->toDateString(), true, now()->addDay())) {
            foreach (rescue(fn () => app(OrderBook::class)->dueReorders(), []) as $r) {
                if (Cache::add('reorder-hint:'.$r['user']->id.':'.md5($r['product'].$r['last']), true, now()->addDays(120))) {
                    app(TelegramNotifier::class)->send('🔁 <b>Reorder due?</b> '.e($r['user']->displayName() ?? 'Telegram '.$r['user']->telegram_id)
                        ."\nUsually orders <b>".e(mb_strimwidth($r['product'], 0, 100, '…')).'</b> about every '.$r['every'].' days — last order '.$r['since'].' days ago.'
                        ."\n<a href=\"".e(SeoFiles::publicUrl(route('admin.telegram-users.show', $r['user']))).'">Open the contact</a>');
                    $hints++;
                }
            }
        }
        $this->info("Answered {$answered}, reminded {$reminded}, closed {$closed} as unfinished, followed up {$followedUp}, draft reminders {$nudged}, reorder hints {$hints}.");

        return self::SUCCESS;
    }
}
