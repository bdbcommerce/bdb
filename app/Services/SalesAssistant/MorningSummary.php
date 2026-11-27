<?php

namespace App\Services\SalesAssistant;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Services\Seo\SeoFiles;
use App\Services\TelegramNotifier;
use Illuminate\Support\Carbon;

/**
 * Walk-through step 7: when the office opens, the sales chat gets one message about the customers who wrote while
 * it was closed (since the last closing time): who, what they want (the AI's summary, else their first words),
 * whether the AI answered and whether they still wait, and the requests (leads) made meanwhile. Once a working day,
 * from sales:assistant-tick; nothing is sent when nobody wrote.
 */
class MorningSummary
{
    public const MAX_CHATS = 15; // Telegram's 4096 characters

    public function __construct(private TelegramNotifier $notifier) {}

    public function due(SalesAssistantSetting $settings, ?Carbon $now = null): bool
    {
        $now ??= now();

        return $settings->morning_summary && $settings->isWorkingTime($now)
            && $settings->morning_summary_sent_on?->toDateString() !== $now->copy()->setTimezone(SalesAssistantSetting::TZ)->toDateString();
    }

    /** Sends the summary when due; returns the number of chats in it (0 = nothing sent). */
    public function run(SalesAssistantSetting $settings, ?Carbon $now = null): int
    {
        $now ??= now();
        if (! $this->due($settings, $now)) {
            return 0;
        }
        // Marked first: a failing send is not retried every minute (the chats are in Waiting for reply anyway).
        $settings->update(['morning_summary_sent_on' => $now->copy()->setTimezone(SalesAssistantSetting::TZ)->toDateString()]);
        $from = $settings->previousClosing($now);
        $to = $now->copy()->setTimezone(SalesAssistantSetting::TZ)->setTimeFromTimeString((string) ($settings->work_start ?: '09:00'));
        $chats = $this->chats($from, $to);
        $leads = ChatLead::where('created_at', '>=', $from->copy()->utc())->where('created_at', '<', $to->copy()->utc())->count(); // the columns are UTC
        if ($chats === [] && $leads === 0) {
            return 0;
        }
        $this->notifier->send($this->text($chats, $leads, $from));

        return count($chats);
    }

    /**
     * Customers who wrote between $from and $to (one line per customer, the latest session).
     *
     * @return list<array{name: string, wrote: int, what: string, ai: bool, waiting: bool, url: string}>
     */
    public function chats(Carbon $from, Carbon $to): array
    {
        $rows = [];
        ChatConversation::whereIn('channel', ['telegram', 'business'])->where('updated_at', '>=', $from->copy()->utc())->orderByDesc('id')->limit(500)->get()
            ->groupBy(fn (ChatConversation $c) => $c->external_id ?? 'id:'.$c->id)
            ->each(function ($sessions) use ($from, $to, &$rows) {
                $wrote = 0;
                $first = '';
                $ai = false;
                foreach ($sessions->sortBy('id') as $c) {
                    foreach ((array) $c->messages as $m) {
                        $at = is_array($m) && is_string($m['at'] ?? null) ? rescue(fn () => Carbon::parse($m['at']), null, false) : null;
                        if (! $at || $at->lt($from) || $at->gte($to) || ! empty($m['deleted']) || ! empty($m['late_edit'])) {
                            continue;
                        }
                        if (($m['role'] ?? null) === 'user') {
                            $wrote++;
                            $first = $first !== '' ? $first : (is_string($m['content'] ?? null) ? trim($m['content']) : '');
                        } elseif (($m['role'] ?? null) === 'assistant' && empty($m['off_hours'])) {
                            $ai = true;
                        }
                    }
                }
                if ($wrote === 0) {
                    return;
                }
                $latest = ChatConversation::newestOf($sessions);
                $user = $latest->external_id ? TelegramUser::where('telegram_id', $latest->external_id)->first() : null;
                if ($user && $user->contact_type === TelegramUser::TYPE_EMPLOYEE) {
                    return;
                }
                $summary = trim((string) ($latest->sales_state['summary'] ?? ''));
                $rows[] = [
                    'name' => (string) ($user?->displayName() ?: 'Telegram '.$latest->external_id),
                    'wrote' => $wrote,
                    'what' => mb_strimwidth(preg_replace('/\s+/u', ' ', $summary !== '' ? $summary : ($first !== '' ? '“'.$first.'”' : 'photo / file')), 0, 120, '…'),
                    'ai' => $ai,
                    'waiting' => (bool) $latest->awaiting_reply,
                    'url' => SeoFiles::publicUrl(route('admin.chat-conversations.show', $latest)),
                ];
            });

        // Still waiting first.
        usort($rows, fn ($a, $b) => [$b['waiting'], $b['wrote']] <=> [$a['waiting'], $a['wrote']]);

        return $rows;
    }

    private function text(array $chats, int $leads, Carbon $from): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $waiting = count(array_filter($chats, fn ($c) => $c['waiting']));
        $lines = ['☀️ <b>While the office was closed</b> (since '.$from->format('d.m H:i').')',
            count($chats).' '.(count($chats) === 1 ? 'customer' : 'customers').' wrote, '.$waiting.' still '.($waiting === 1 ? 'waits' : 'wait').' for a reply'
                .($leads ? ', '.$leads.' new '.($leads === 1 ? 'request' : 'requests') : '').'.', ''];
        foreach (array_slice($chats, 0, self::MAX_CHATS) as $c) {
            $lines[] = ($c['waiting'] ? '⏳' : '✓').' <a href="'.$e($c['url']).'">'.$e(mb_strimwidth($c['name'], 0, 60, '…')).'</a> — '.$e($c['what'])
                .' <i>('.$c['wrote'].' msg'.($c['ai'] ? ', AI answered' : '').')</i>';
        }
        if (count($chats) > self::MAX_CHATS) {
            $lines[] = '… and '.(count($chats) - self::MAX_CHATS).' more in Waiting for reply.';
        }
        if ($leads) {
            $lines[] = '';
            $lines[] = '<a href="'.$e(SeoFiles::publicUrl(route('admin.chat-leads.index'))).'">Open the requests</a>';
        }

        return implode("\n", $lines);
    }
}
