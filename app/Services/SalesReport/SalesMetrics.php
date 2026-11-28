<?php

namespace App\Services\SalesReport;

use App\Models\AiShadowDraft;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\Client;
use App\Models\CompanyLink;
use App\Models\ExpertQuestion;
use App\Models\Order;
use App\Models\SalesAssistantSetting;
use App\Services\SalesAssistant\OrderBook;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Walk-through step 8: the measured numbers of a sales report period [from, to) — counted from the database, not by
 * Claude: the AI team (chats, replies, hand-overs, shadow drafts, expert questions), orders and companies, manager
 * speed (first-reply times from SalesReporter::collect, requests taken, chats waiting over an hour) and off-hours
 * traffic. Stored on the report (sales_reports.metrics), posted as one Telegram message, shown on the admin page
 * with the change against the previous report of the same period.
 */
class SalesMetrics
{
    /** Numbers compared with the previous report on the admin page: section => keys. */
    public const COMPARED = [
        'ai' => ['chats', 'replies', 'handovers', 'leads'],
        'orders' => ['confirmed', 'confirmed_sum', 'drafts', 'cancelled'],
        'companies' => ['linked_by_ai', 'linked_by_manager', 'new_clients'],
        'managers' => ['leads_taken', 'leads_not_taken', 'waiting_over_hour'],
        'off_hours' => ['messages', 'customers', 'answered_by_ai'],
    ];

    private const LABELS = [
        'en' => [
            'title' => 'Numbers', 'ai' => 'AI team', 'chats' => 'chats', 'replies' => 'replies', 'handovers' => 'hand-overs', 'leads' => 'requests',
            'shadow' => 'shadow drafts', 'sent' => 'sent', 'edited' => 'edited', 'discarded' => 'discarded', 'experts' => 'expert questions', 'answered' => 'answered',
            'avg' => 'avg', 'orders' => 'Orders', 'confirmed' => 'confirmed', 'drafts' => 'drafts', 'cancelled' => 'cancelled', 'top' => 'top',
            'companies' => 'companies linked', 'by_ai' => 'by the AI', 'by_manager' => 'by managers', 'new_clients' => 'new companies',
            'managers' => 'Managers', 'reply' => 'first reply', 'taken' => 'requests taken', 'not_taken' => 'not taken', 'waiting' => 'chats waiting over 1 h',
            'off_hours' => 'Off hours', 'messages' => 'messages', 'customers' => 'customers', 'answered_ai' => 'answered by the AI', 'holiday' => 'on holidays', 'sum' => 'sum',
        ],
        'ru' => [
            'title' => 'Цифры', 'ai' => 'AI-команда', 'chats' => 'чатов', 'replies' => 'ответов', 'handovers' => 'передач', 'leads' => 'заявок',
            'shadow' => 'черновиков', 'sent' => 'отправлено', 'edited' => 'исправлено', 'discarded' => 'отклонено', 'experts' => 'вопросов экспертам', 'answered' => 'отвечено',
            'avg' => 'в среднем', 'orders' => 'Заказы', 'confirmed' => 'подтверждено', 'drafts' => 'черновиков', 'cancelled' => 'отменено', 'top' => 'топ',
            'companies' => 'компаний привязано', 'by_ai' => 'AI', 'by_manager' => 'менеджерами', 'new_clients' => 'новых компаний',
            'managers' => 'Менеджеры', 'reply' => 'первый ответ', 'taken' => 'заявок взято', 'not_taken' => 'не взято', 'waiting' => 'чатов ждут больше 1 ч',
            'off_hours' => 'Нерабочее время', 'messages' => 'сообщений', 'customers' => 'клиентов', 'answered_ai' => 'ответил AI', 'holiday' => 'в праздники', 'sum' => 'сум',
        ],
        'uz' => [
            'title' => 'Raqamlar', 'ai' => 'AI jamoa', 'chats' => 'suhbat', 'replies' => 'javob', 'handovers' => 'topshirish', 'leads' => 'soʻrov',
            'shadow' => 'qoralama', 'sent' => 'yuborildi', 'edited' => 'tuzatildi', 'discarded' => 'rad etildi', 'experts' => 'ekspert savollari', 'answered' => 'javob berildi',
            'avg' => 'oʻrtacha', 'orders' => 'Buyurtmalar', 'confirmed' => 'tasdiqlandi', 'drafts' => 'qoralama', 'cancelled' => 'bekor qilindi', 'top' => 'top',
            'companies' => 'kompaniya bogʻlandi', 'by_ai' => 'AI', 'by_manager' => 'menejerlar', 'new_clients' => 'yangi kompaniya',
            'managers' => 'Menejerlar', 'reply' => 'birinchi javob', 'taken' => 'soʻrov olindi', 'not_taken' => 'olinmadi', 'waiting' => '1 soatdan ortiq kutayotgan suhbat',
            'off_hours' => 'Ish vaqtidan tashqari', 'messages' => 'xabar', 'customers' => 'mijoz', 'answered_ai' => 'AI javob berdi', 'holiday' => 'bayramda', 'sum' => 'soʻm',
        ],
    ];

    /**
     * @param  array<string, list<int>>  $replyTimes  person => first-reply seconds (SalesReporter::collect)
     */
    public function measure(Carbon $from, Carbon $to, array $replyTimes = []): array
    {
        $utcFrom = $from->copy()->utc();
        $utcTo = $to->copy()->utc();
        $between = fn ($q, string $column) => $q->where($column, '>=', $utcFrom)->where($column, '<', $utcTo);

        $leads = fn () => ChatLead::whereIn('channel', ['telegram', 'business']);
        $ai = $this->ai($from, $to, $between); // also counts the off-hours traffic

        return [
            'ai' => $ai,
            'orders' => $this->orders($between),
            'companies' => [
                'linked_by_ai' => $between(CompanyLink::where('linked_by', 'ai'), 'created_at')->count(),
                'linked_by_manager' => $between(CompanyLink::where('linked_by', 'manager'), 'created_at')->count(),
                'new_clients' => $between(Client::query(), 'created_at')->count(),
            ],
            'managers' => [
                // One row per person: "Staff: Aziz" (admin panel) and "Sales: Aziz" (Premium account) are the same Aziz.
                'people' => collect($replyTimes)->groupBy(fn ($list, $who) => mb_substr(trim((string) preg_replace('/^(Staff|Sales): /', '', (string) $who)), 0, 40), true)
                    ->map(function ($lists, string $name) {
                        $list = $lists->flatten()->map(fn ($s) => (int) $s)->sort()->values()->all();

                        return ['name' => $name, 'replies' => count($list),
                            'avg_seconds' => (int) round(array_sum($list) / max(1, count($list))), 'median_seconds' => (int) ($list[intdiv(count($list), 2)] ?? 0)];
                    })->filter(fn ($p) => $p['replies'] > 0)->sortByDesc('replies')->values()->take(15)->all(),
                // Requests from the Telegram chats (the same as ai.leads).
                'leads_taken' => $between($leads()->whereNotNull('taken_at'), 'created_at')->count(),
                'leads_not_taken' => $between($leads()->whereNull('taken_at')->where('is_handled', false), 'created_at')->count(),
                'taken_by' => $between($leads()->whereNotNull('taken_by'), 'created_at')->get(['taken_by'])->countBy(fn ($l) => mb_substr((string) $l->taken_by, 0, 40))->sortDesc()->take(10)->all(),
                // Waiting now (the latest session of each chat only), for more than an hour before the period's end,
                // and not older than 30 days (abandoned chats are not a manager's speed).
                'waiting_over_hour' => ChatConversation::query()->awaitingReply()->where('last_message_at', '>=', $utcTo->copy()->subDays(30))
                    ->where('last_message_at', '<', $utcTo->copy()->subHour())->distinct()->count('external_id'),
            ],
            'off_hours' => $this->offHours,
        ];
    }

    /** Filled by ai(), which reads every message of the period once. */
    private array $offHours = ['messages' => 0, 'customers' => 0, 'answered_by_ai' => 0, 'holiday_messages' => 0, 'notes' => 0];

    private function ai(Carbon $from, Carbon $to, callable $between): array
    {
        $settings = SalesAssistantSetting::current();
        $chats = $replies = $handovers = 0;
        $off = ['messages' => 0, 'customers' => 0, 'answered_by_ai' => 0, 'holiday_messages' => 0, 'notes' => 0];
        ChatConversation::whereIn('channel', ['telegram', 'business'])->where('updated_at', '>=', $from->copy()->utc())->orderBy('id')->get()
            ->groupBy(fn (ChatConversation $c) => $c->external_id ?? 'id:'.$c->id)
            ->each(function ($sessions) use ($from, $to, $settings, &$chats, &$replies, &$handovers, &$off) {
                $ai = false;
                $offCustomer = false;
                $offAnswered = false;
                $waitingOff = false; // a customer message outside working hours not answered yet
                foreach ($sessions->sortBy('id') as $c) {
                    foreach ((array) $c->messages as $m) {
                        $at = is_array($m) && is_string($m['at'] ?? null) ? rescue(fn () => Carbon::parse($m['at']), null, false) : null;
                        if (! $at || $at->lt($from) || $at->gte($to) || ! empty($m['late_edit'])) {
                            continue;
                        }
                        $role = $m['role'] ?? null;
                        if ($role === 'user' && empty($m['aside'])) {
                            if (! $settings->isWorkingTime($at)) {
                                $off['messages']++;
                                $offCustomer = $waitingOff = true;
                                $settings->holidayOn($at) !== null && $off['holiday_messages']++;
                            }
                        } elseif ($role === 'assistant') {
                            if (! empty($m['off_hours'])) {
                                $off['notes']++;
                            } elseif (! empty($m['handoff'])) {
                                $handovers++;
                            } elseif (! ChatConversation::isCourtesy($m)) {
                                $replies++;
                                $ai = true;
                                if ($waitingOff && ! $settings->isWorkingTime($at)) { // the AI covered the night, not the morning shift
                                    $offAnswered = true;
                                    $waitingOff = false;
                                }
                            }
                        } elseif (in_array($role, ['admin', 'owner'], true)) {
                            $waitingOff = false;
                        }
                        if ($waitingOff && $settings->isWorkingTime($at)) {
                            $waitingOff = false; // the office opened before the AI answered
                        }
                    }
                }
                $chats += (int) $ai;
                $off['customers'] += (int) $offCustomer;
                $off['answered_by_ai'] += (int) $offAnswered;
            });
        $this->offHours = $off;

        $experts = $between(ExpertQuestion::whereNull('parent_id'), 'created_at')->get(['status', 'created_at', 'answered_at']);
        $answered = $experts->filter(fn ($q) => $q->status === 'answered' && $q->answered_at);

        return [
            'chats' => $chats, 'replies' => $replies, 'handovers' => $handovers,
            'leads' => $between(ChatLead::whereIn('channel', ['telegram', 'business']), 'created_at')->count(),
            'shadow' => $between(AiShadowDraft::query(), 'created_at')->get(['status'])->countBy('status')->all(),
            'experts' => ['asked' => $experts->count(), 'answered' => $answered->count(),
                'avg_answer_minutes' => $answered->isEmpty() ? null : (int) round($answered->avg(fn ($q) => $q->created_at->diffInMinutes($q->answered_at, true)))],
        ];
    }

    private function orders(callable $between): array
    {
        $confirmed = $between(Order::where('status', 'confirmed'), 'confirmed_at')->get(['items', 'quoted']);
        $products = [];
        foreach ($confirmed as $o) {
            foreach ((array) $o->items as $i) {
                $name = is_array($i) && is_string($i['product'] ?? null) ? mb_substr(trim($i['product']), 0, 60) : '';
                if ($name !== '') {
                    $products[$name] = ($products[$name] ?? 0) + 1;
                }
            }
        }
        arsort($products);

        return [
            'confirmed' => $confirmed->count(),
            'confirmed_sum' => (int) $confirmed->sum(fn (Order $o) => OrderBook::sum((array) $o->quoted)),
            'drafts' => $between(Order::where('status', 'draft'), 'created_at')->count(),
            'cancelled' => $between(Order::where('status', 'cancelled'), 'cancelled_at')->count(),
            'top_products' => array_slice($products, 0, 5, true),
        ];
    }

    /** The numbers as one Telegram HTML message in the report language. */
    public function telegram(array $m, string $lang): string
    {
        $l = fn (string $k) => self::LABELS[$lang][$k] ?? self::LABELS['en'][$k];
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = fn ($v) => number_format((int) $v, 0, '', ' ');
        $time = fn (int $s) => ($min = (int) round($s / 60)) < 60 ? max(1, $min).' min' : round($min / 60, 1).' h';
        $ai = $m['ai'] ?? [];
        $shadow = (array) ($ai['shadow'] ?? []);
        $lines = ['📈 <b>'.$e($l('title')).'</b>', '',
            '🤖 <b>'.$e($l('ai')).'</b>: '.$n($ai['chats'] ?? 0).' '.$e($l('chats')).' · '.$n($ai['replies'] ?? 0).' '.$e($l('replies')).' · '.$n($ai['handovers'] ?? 0).' '.$e($l('handovers')).' · '.$n($ai['leads'] ?? 0).' '.$e($l('leads'))];
        if (array_sum($shadow)) {
            $lines[] = '   '.$e($l('shadow')).': '.$n(array_sum($shadow)).' ('.$e($l('sent')).' '.$n($shadow['sent'] ?? 0).', '.$e($l('edited')).' '.$n($shadow['edited'] ?? 0).', '.$e($l('discarded')).' '.$n($shadow['discarded'] ?? 0).')';
        }
        if ($ai['experts']['asked'] ?? 0) {
            $lines[] = '   '.$e($l('experts')).': '.$n($ai['experts']['asked']).', '.$e($l('answered')).' '.$n($ai['experts']['answered'])
                .($ai['experts']['avg_answer_minutes'] !== null ? ' · '.$e($l('avg')).' '.$time($ai['experts']['avg_answer_minutes'] * 60) : '');
        }
        $o = $m['orders'] ?? [];
        $lines[] = '🛒 <b>'.$e($l('orders')).'</b>: '.$n($o['confirmed'] ?? 0).' '.$e($l('confirmed')).(($o['confirmed_sum'] ?? 0) ? ' ≈ '.$n($o['confirmed_sum']).' '.$e($l('sum')) : '')
            .' · '.$n($o['drafts'] ?? 0).' '.$e($l('drafts')).' · '.$n($o['cancelled'] ?? 0).' '.$e($l('cancelled'));
        if ($o['top_products'] ?? []) {
            $lines[] = '   '.$e($l('top')).': '.implode(', ', array_map(fn ($p, $c) => $e($p).' ×'.$c, array_keys($o['top_products']), $o['top_products']));
        }
        $c = $m['companies'] ?? [];
        $lines[] = '🏢 '.$n(($c['linked_by_ai'] ?? 0) + ($c['linked_by_manager'] ?? 0)).' '.$e($l('companies')).' ('.$e($l('by_ai')).' '.$n($c['linked_by_ai'] ?? 0).', '.$e($l('by_manager')).' '.$n($c['linked_by_manager'] ?? 0).') · '.$n($c['new_clients'] ?? 0).' '.$e($l('new_clients'));
        $mg = $m['managers'] ?? [];
        $lines[] = '👥 <b>'.$e($l('managers')).'</b>: '.$n($mg['leads_taken'] ?? 0).' '.$e($l('taken')).', '.$n($mg['leads_not_taken'] ?? 0).' '.$e($l('not_taken')).' · '.$n($mg['waiting_over_hour'] ?? 0).' '.$e($l('waiting'));
        foreach (array_slice((array) ($mg['people'] ?? []), 0, 8) as $p) {
            $lines[] = '   '.$e($p['name']).': '.$n($p['replies']).' · '.$e($l('reply')).' ~'.$time((int) $p['avg_seconds']);
        }
        $off = $m['off_hours'] ?? [];
        $lines[] = '🌙 <b>'.$e($l('off_hours')).'</b>: '.$n($off['messages'] ?? 0).' '.$e($l('messages')).', '.$n($off['customers'] ?? 0).' '.$e($l('customers')).' · '.$e($l('answered_ai')).' '.$n($off['answered_by_ai'] ?? 0)
            .(($off['holiday_messages'] ?? 0) ? ' · '.$n($off['holiday_messages']).' '.$e($l('holiday')) : '');

        return implode("\n", $lines);
    }

    /** Compact JSON for Claude, so its conclusion can use the measured numbers. */
    public static function forPrompt(array $m): string
    {
        unset($m['managers']['taken_by']);

        try {
            return json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '{}';
        }
    }
}
