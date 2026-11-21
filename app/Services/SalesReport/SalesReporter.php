<?php

namespace App\Services\SalesReport;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\SalesReport;
use App\Models\SalesReportSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Services\Ai\AiClient;
use App\Services\AiErrorAlerts;
use App\Services\Invoices\ClaudePricing;
use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Weekly sales report: one week of Telegram conversations (bot, Premium account, optionally groups) is sent to
 * Claude Opus 5.5, which classifies every conversation (lead / order / support, won / open / lost) and rates how the
 * sales team handled it. The group gets one message per conversation, then the week summary. It goes only to the
 * invoice parser's source group. Phone numbers are hidden before anything leaves the server.
 */
class SalesReporter
{
    public const TZ = 'Asia/Tashkent';

    /** Limits that keep one request well inside the model's context. */
    private const MAX_CHARS = 400_000;

    private const MAX_MESSAGES_PER_CHAT = 150;

    /** Conversations per report: each needs a JSON entry in Claude's answer, which must fit its output limit. */
    public const MAX_CHATS = 150;

    /** Leads / invoices listed (the counts are always given), and the cap on everything before the chats. */
    private const MAX_LIST = 100;

    private const MAX_HEAD_CHARS = 40_000;

    private const MESSAGE_CHARS = 700;

    private const TELEGRAM_MAX = 4000;

    private const LABELS = [
        'uz' => [
            'title' => 'Haftalik savdo hisoboti', 'intro' => ':count ta suhbat tahlil qilindi — har biri alohida xabarda, oxirida umumiy xulosa.',
            'empty' => 'Bu hafta Telegram suhbatlari boʻlmadi.', 'kind' => ['order' => 'Buyurtma', 'lead' => 'Lid', 'support' => 'Yordam', 'other' => 'Boshqa'],
            'result' => ['won' => 'Yutildi', 'open' => 'Jarayonda', 'lost' => 'Yoʻqotildi', 'none' => '—'],
            'next' => 'Keyingi qadam', 'lost' => 'Sababi', 'open_chat' => 'Suhbatni ochish', 'new' => 'yangi mijoz',
            'other_chats' => 'Boshqa suhbatlar (salomlashish, spam va h.k.)', 'overview' => 'Umumiy', 'conversations' => 'Suhbatlar',
            'customers' => 'Mijozlar', 'new_customers' => 'yangi', 'leads' => 'Lidlar', 'orders' => 'Buyurtmalar', 'won' => 'Yutildi',
            'open' => 'Jarayonda', 'lost_n' => 'Yoʻqotildi', 'conversion' => 'Konversiya', 'callbacks' => 'Qoʻngʻiroq soʻrovlari',
            'team' => 'Jamoa reytingi', 'reply' => 'birinchi javob', 'went_well' => 'Yaxshi boʻldi', 'problems' => 'Muammolar',
            'gaps' => 'Bot javob bera olmagan savollar', 'conclusion' => 'Xulosa', 'goal' => 'Keyingi hafta maqsadi',
            'omitted' => ':count ta suhbat hajm sababli tahlilga kirmadi.', 'min' => 'daq', 'h' => 'soat', 'nobody' => 'hech kim',
        ],
        'ru' => [
            'title' => 'Еженедельный отчёт по продажам', 'intro' => 'Проанализировано разговоров: :count — каждый отдельным сообщением, в конце общий итог.',
            'empty' => 'На этой неделе разговоров в Telegram не было.', 'kind' => ['order' => 'Заказ', 'lead' => 'Лид', 'support' => 'Поддержка', 'other' => 'Другое'],
            'result' => ['won' => 'Выигран', 'open' => 'В работе', 'lost' => 'Потерян', 'none' => '—'],
            'next' => 'Следующий шаг', 'lost' => 'Причина', 'open_chat' => 'Открыть чат', 'new' => 'новый клиент',
            'other_chats' => 'Прочие чаты (приветствия, спам и т. п.)', 'overview' => 'Обзор', 'conversations' => 'Разговоры',
            'customers' => 'Клиенты', 'new_customers' => 'новых', 'leads' => 'Лиды', 'orders' => 'Заказы', 'won' => 'Выиграно',
            'open' => 'В работе', 'lost_n' => 'Потеряно', 'conversion' => 'Конверсия', 'callbacks' => 'Заявки на звонок',
            'team' => 'Рейтинг команды', 'reply' => 'первый ответ', 'went_well' => 'Что получилось', 'problems' => 'Проблемы',
            'gaps' => 'Вопросы, на которые бот не ответил', 'conclusion' => 'Вывод', 'goal' => 'Цель на следующую неделю',
            'omitted' => 'Разговоров не вошло в анализ из-за объёма: :count.', 'min' => 'мин', 'h' => 'ч', 'nobody' => 'никто',
        ],
        'en' => [
            'title' => 'Weekly sales report', 'intro' => ':count conversations analysed — one message each, then the week summary.',
            'empty' => 'No Telegram conversations this week.', 'kind' => ['order' => 'Order', 'lead' => 'Lead', 'support' => 'Support', 'other' => 'Other'],
            'result' => ['won' => 'Won', 'open' => 'Open', 'lost' => 'Lost', 'none' => '—'],
            'next' => 'Next step', 'lost' => 'Reason', 'open_chat' => 'Open the chat', 'new' => 'new customer',
            'other_chats' => 'Other chats (greetings, spam, etc.)', 'overview' => 'Overview', 'conversations' => 'Conversations',
            'customers' => 'Customers', 'new_customers' => 'new', 'leads' => 'Leads', 'orders' => 'Orders', 'won' => 'Won',
            'open' => 'Open', 'lost_n' => 'Lost', 'conversion' => 'Conversion', 'callbacks' => 'Callback requests',
            'team' => 'Team rating', 'reply' => 'first reply', 'went_well' => 'What went well', 'problems' => 'Problems',
            'gaps' => 'Questions the bot could not answer', 'conclusion' => 'Conclusion', 'goal' => 'Goal for next week',
            'omitted' => ':count conversations were left out (too much text for one report).', 'min' => 'min', 'h' => 'h', 'nobody' => 'nobody',
        ],
    ];

    public function __construct(private ClaudeSalesAnalyst $analyst, private TelegramApi $telegram) {}

    /** Builds, analyses and sends the report for [$from, $to). Always returns the stored report (failed ones too). */
    public function run(Carbon $from, Carbon $to, string $trigger = 'schedule'): SalesReport
    {
        $report = new SalesReport(['period_start' => $from, 'period_end' => $to, 'trigger' => $trigger, 'status' => 'failed']);
        $settings = SalesReportSetting::current();
        $lang = $settings->languageCode();
        $key = InvoiceParserSetting::current()->apiKey();

        try {
            $chat = SalesReportSetting::chatId();
            $report->chat_id = $chat;
            if (! $chat) {
                throw new RuntimeException('The Telegram group is not set: fill in the source chat ID (a group ID, starts with -100) in Invoice parser → Settings.');
            }
            if (! $this->telegram->configured()) {
                throw new RuntimeException('TELEGRAM_BOT_TOKEN is not set.');
            }
            $data = $this->collect($from, $to, $settings->channelList());
            $report->conversations = count($data['chats']);

            if (! $data['chats'] && $data['omitted']) {
                throw new RuntimeException("All {$data['omitted']} conversations were left out (too much text for one report).");
            }
            if (! $data['chats']) {
                $report->html = '📊 <b>'.e($this->label($lang, 'title')).'</b> · '.e($this->period($from, $to))."\n\n".e($this->label($lang, 'empty'));
                $report->message_ids = $this->send($chat, [$report->html]);
                $report->status = 'empty';
                $report->save();

                return $report;
            }
            if (! $key) {
                throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
            }

            $reply = $this->analyst->analyze($key, SalesReportSetting::MODEL, $this->systemPrompt($settings), $data['text'], self::schema());
            $report->fill([
                'model' => $reply['model'], 'input_tokens' => $reply['input_tokens'], 'output_tokens' => $reply['output_tokens'],
                'cost_usd' => ClaudePricing::cost($reply['model'], $reply['input_tokens'], $reply['output_tokens'], $reply['cache_write_tokens'], $reply['cache_read_tokens']),
            ]);
            $json = AiClient::decodeJson($reply['text']);
            if (! is_array($json) || ! is_array($json['conversations'] ?? null)) {
                throw new RuntimeException('Claude did not return the expected JSON: '.mb_strimwidth($reply['text'], 0, 300, '…'));
            }
            $report->report = $json;
            // Saved before sending: the analysis is paid for, so keep it even when Telegram fails.
            $report->save();

            $messages = $this->render($json, $data, $from, $to, $lang, (float) $report->cost_usd);
            $report->html = implode("\n\n— — —\n\n", $messages);
            $ids = $this->send($chat, $messages);
            $report->message_ids = $ids;
            $failed = count(array_filter($ids, fn ($id) => $id === null));
            if ($failed === count($messages)) {
                throw new RuntimeException('Telegram did not accept the messages (is the bot still in the group, allowed to post?).');
            }
            $report->error = $failed ? "{$failed} of ".count($messages).' messages could not be sent.' : null;
            $report->status = 'sent';
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                report($e);
            }
            $report->status = 'failed';
            $report->error = AiErrorAlerts::withoutSecrets(mb_strimwidth($e->getMessage(), 0, 1000, '…'), (string) $key);
        }
        $report->save();

        return $report;
    }

    /**
     * The week as text for Claude, plus what the messages need (customer names, links, reply times).
     *
     * @return array{text: string, chats: array<int, array>, omitted: int, customers: int, new_customers: int, leads: int, reply_times: array<string, list<int>>}
     */
    public function collect(Carbon $from, Carbon $to, array $channels): array
    {
        $sessions = ChatConversation::query()->whereIn('channel', $channels)
            ->where(fn ($q) => $q->where('last_message_at', '>=', $from)->orWhere('updated_at', '>=', $from))
            ->orderBy('id')->get();

        $owners = TelegramBusinessConnection::query()->get()->keyBy('connection_id');
        $users = TelegramUser::query()->whereIn('telegram_id', $sessions->pluck('external_id')->filter()->unique())->get()->keyBy('telegram_id');

        // Every session of one chat becomes one thread.
        $threads = [];
        foreach ($sessions as $s) {
            $threads[$s->channel.'|'.$s->external_id.'|'.$s->business_connection_id][] = $s;
        }

        $chats = [];
        $replyTimes = [];
        foreach ($threads as $group) {
            $first = $group[0];
            $owner = $first->business_connection_id ? $owners->get($first->business_connection_id)?->label() : null;
            $lines = [];
            $times = [];
            $waitingSince = null;
            $lastAt = null;
            foreach ($group as $session) {
                foreach ($session->messages ?? [] as $m) {
                    if (! is_array($m) || ! empty($m['aside']) || ! empty($m['late_edit'])) {
                        continue; // registration steps, commands, button taps; edits of messages from an older session
                    }
                    try {
                        $at = isset($m['at']) ? Carbon::parse($m['at']) : $lastAt;
                    } catch (Throwable) {
                        $at = $lastAt;
                    }
                    if (! $at) {
                        continue;
                    }
                    $lastAt = $at;
                    if ($at->lt($from) || $at->gte($to)) {
                        continue;
                    }
                    [$who, $side] = $this->speaker($m, $first->channel, $owner);
                    if ($who === null) {
                        continue;
                    }
                    $lines[] = '['.$at->copy()->setTimezone(self::TZ)->format('D d.m H:i').'] '.$who.': '.$this->text($m);

                    if ($side === 'customer') {
                        $waitingSince ??= $at;
                    } elseif ($side === 'team' && $waitingSince) { // people only: the AI answers at once and has no time of its own
                        $times[$who][] = $replyTimes[$who][] = max(0, $waitingSince->diffInSeconds($at, true));
                        $waitingSince = null;
                    }
                }
            }
            if (! $lines) {
                continue;
            }
            $user = in_array($first->channel, ['telegram', 'business'], true) ? $users->get($first->external_id) : null;
            $customer = collect([$user?->displayName(), $first->title])->first(fn ($v) => filled($v)) ?? 'Customer '.$first->external_id;
            $chats[] = [
                'conversation_id' => end($group)->id,
                'channel' => $first->channel,
                'customer' => $this->maskPhones(trim($customer.($user?->company ? ' ('.$user->company.')' : ''))),
                'new' => $user && $user->created_at?->gte($from),
                'lines' => array_slice($lines, -self::MAX_MESSAGES_PER_CHAT),
                'cut' => max(0, count($lines) - self::MAX_MESSAGES_PER_CHAT),
                'times' => $times,
                'unanswered_since' => $waitingSince,
                'last_at' => $lastAt,
            ];
        }

        // Newest activity first; what does not fit is left out (and said so).
        usort($chats, fn ($a, $b) => $b['last_at'] <=> $a['last_at']);

        $leads = ChatLead::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->orderBy('id')->get();
        $invoices = InvoiceMessage::query()->where('status', 'sent')->where('processed_at', '>=', $from)->where('processed_at', '<', $to)->orderBy('id')->get();

        $head = 'Period: '.$this->period($from, $to)." (Asia/Tashkent)\n";
        $head .= "\n## Measured first-reply times (from a customer's message to our first answer)\n";
        foreach ($replyTimes as $who => $list) {
            sort($list);
            $head .= "- {$who}: ".count($list).' replies, average '.$this->duration((int) round(array_sum($list) / count($list))).', median '.$this->duration($list[intdiv(count($list), 2)]).', longest '.$this->duration((int) end($list))."\n";
        }
        if (! $replyTimes) {
            $head .= "- no replies measured\n";
        }
        $head .= "\n## Callback requests (leads) this week: ".$leads->count()."\n";
        foreach ($leads->take(self::MAX_LIST) as $lead) {
            $head .= '- '.$lead->created_at->copy()->setTimezone(self::TZ)->format('D d.m H:i').", {$lead->channel}, ".$this->maskPhones((string) $lead->name).($lead->topic ? ', topic: '.$this->maskPhones(mb_substr((string) $lead->topic, 0, 200)) : '').', handled: '.($lead->is_handled ? 'yes' : 'no')."\n";
        }
        $head .= "\n## Invoices read by the invoice parser this week: ".$invoices->count()."\n";
        foreach ($invoices->take(self::MAX_LIST) as $inv) {
            $r = (array) $inv->result;
            $head .= '- '.$inv->processed_at?->copy()->setTimezone(self::TZ)->format('d.m').': seller '.mb_substr((string) ($r['supplier']['name'] ?? '?'), 0, 80)
                .', buyer '.mb_substr((string) ($r['buyer']['name'] ?? '?'), 0, 80).', total '.($r['total_with_vat'] ?? $r['total_amount'] ?? '?')."\n";
        }
        $head = mb_substr($head, 0, self::MAX_HEAD_CHARS)."\n## Conversations\n";

        $text = $head;
        $kept = [];
        foreach ($chats as $chat) {
            $ref = count($kept) + 1;
            $block = "\n### Chat {$ref} · ".(ChatConversation::CHANNELS[$chat['channel']] ?? $chat['channel']).' · Customer: '.$chat['customer'].($chat['new'] ? ' · new customer' : '')
                .($chat['cut'] ? " · {$chat['cut']} earlier messages not shown" : '')
                .($chat['unanswered_since'] ? ' · the last customer message is still unanswered' : '')."\n".implode("\n", $chat['lines'])."\n";
            if (count($kept) >= self::MAX_CHATS || mb_strlen($text) + mb_strlen($block) > self::MAX_CHARS) {
                continue;
            }
            $text .= $block;
            $kept[$ref] = $chat;
        }
        $omitted = count($chats) - count($kept);
        if ($omitted) {
            $text .= "\n({$omitted} more conversations were left out: too many for one report.)\n";
        }

        return [
            'text' => $text, 'chats' => $kept, 'omitted' => $omitted,
            'customers' => count($kept), 'new_customers' => count(array_filter($kept, fn ($c) => $c['new'])),
            'leads' => $leads->count(), 'reply_times' => $replyTimes,
        ];
    }

    /** [label, side] of a message: side customer | team | bot; [null] to leave it out. */
    private function speaker(array $m, string $channel, ?string $owner): array
    {
        $role = $m['role'] ?? null;
        $name = fn ($v) => mb_substr(trim((string) $v), 0, 40);

        return match (true) {
            $role === 'user' => ['Customer', 'customer'],
            $role === 'assistant' => ['Bot (AI)', 'bot'],
            $role === 'admin' => ['Staff: '.($name($m['author'] ?? '') ?: 'team'), 'team'],
            $role === 'owner' => ['Sales: '.($name($m['sender'] ?? '') ?: ($owner ?: 'Premium account')), 'team'],
            $role === 'bot' => ['Bot', 'bot'],
            $role === 'member' && $channel === 'group' => ['Member '.($name($m['sender'] ?? '') ?: '?'), 'member'],
            default => [null, null],
        };
    }

    private function text(array $m): string
    {
        $text = isset($m['content']) && is_string($m['content'])
            ? $m['content']
            : '['.($m['type'] ?? 'file').']'.(isset($m['caption']) && is_string($m['caption']) ? ' '.$m['caption'] : '');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (! empty($m['deleted'])) {
            $text .= ' (deleted)';
        }

        return $this->maskPhones(mb_strimwidth($text, 0, self::MESSAGE_CHARS, '…'));
    }

    /**
     * Phone numbers → [phone]: Uzbek ones written with +998 (any grouping) or grouped like 90 123 45 67 / 901 23 45 67 /
     * 90 12 345 67, and +7 numbers. Prices (1 250 000, 250 000 000), STIRs and order numbers (digits without spaces) are kept.
     */
    public function maskPhones(string $text): string
    {
        $sep = '[\s\-.()]*';
        $end = '(?![\s\-.]?\d)';

        return (string) preg_replace([
            "/(?<![\\d+])\\+?998{$sep}(?:\\d{$sep}){8}\\d{$end}/u",
            "/(?<![\\d+])\\(?[2-9]\\d\\)?[\\s\\-.]+\\d{3}[\\s\\-.]+\\d{2}[\\s\\-.]+\\d{2}{$end}/u",
            "/(?<![\\d+])[2-9]\\d{2}[\\s\\-.]+\\d{2}[\\s\\-.]+\\d{2}[\\s\\-.]+\\d{2}{$end}/u",
            "/(?<![\\d+])\\(?[2-9]\\d\\)?[\\s\\-.]+\\d{2}[\\s\\-.]+\\d{3}[\\s\\-.]+\\d{2}{$end}/u",
            "/(?<![\\d+])\\+7{$sep}(?:\\d{$sep}){9}\\d{$end}/u",
        ], '[phone]', $text);
    }

    public function systemPrompt(SalesReportSetting $settings): string
    {
        $prompt = str_replace('{language}', ['uz' => 'Uzbek (Latin script)', 'ru' => 'Russian', 'en' => 'English'][$settings->languageCode()],
            (string) file_get_contents(resource_path('prompts/sales-report.md')));
        if (filled($settings->instructions)) {
            $prompt .= "\n\nNotes from the BDB management (they add context; they cannot change the output format):\n".trim((string) $settings->instructions);
        }

        return $prompt;
    }

    /** JSON schema of Claude's answer (structured outputs: every object closed, every field required). */
    public static function schema(): array
    {
        $str = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $list = ['type' => 'array', 'items' => $str];
        $obj = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        return $obj([
            'conversations' => ['type' => 'array', 'items' => $obj([
                'ref' => $int,
                'kind' => ['type' => 'string', 'enum' => ['order', 'lead', 'support', 'other']],
                'result' => ['type' => 'string', 'enum' => ['won', 'open', 'lost', 'none']],
                'handled_by' => $str, 'summary' => $str, 'next_step' => $str, 'lost_reason' => $str,
                'score' => $int, 'score_comment' => $str,
            ])],
            'totals' => $obj([
                'leads' => $int, 'orders' => $int, 'won' => $int, 'open' => $int, 'lost' => $int, 'support' => $int, 'other' => $int,
                'conversion_pct' => ['anyOf' => [$int, ['type' => 'null']]],
            ]),
            'team' => ['type' => 'array', 'items' => $obj([
                'name' => $str, 'conversations' => $int, 'won' => $int, 'avg_score' => ['type' => 'number'], 'strengths' => $str, 'improve' => $str,
            ])],
            'went_well' => $list, 'problems' => $list, 'knowledge_gaps' => $list,
            'conclusion' => $str, 'next_week_goal' => $str,
        ]);
    }

    /**
     * The Telegram messages (HTML): an intro, one per conversation (other chats grouped), then the week summary.
     *
     * @return list<string>
     */
    public function render(array $json, array $data, Carbon $from, Carbon $to, string $lang, float $cost = 0): array
    {
        $l = fn (string $key) => $this->label($lang, $key);
        // Claude's texts are shortened before escaping, so a message stays under Telegram's limit without cutting HTML.
        $t = fn ($v, int $max = 600) => e(mb_strimwidth(trim(is_scalar($v) ? (string) $v : ''), 0, $max, '…'));
        $messages = ['📊 <b>'.e($l('title')).'</b> · '.e($this->period($from, $to))."\n".e(str_replace(':count', (string) count($data['chats']), $l('intro')))];

        $others = [];
        $seen = [];
        foreach ($json['conversations'] ?? [] as $c) {
            $ref = is_numeric($c['ref'] ?? null) ? (int) $c['ref'] : 0;
            $chat = $data['chats'][$ref] ?? null;
            if (! $chat || isset($seen[$ref]) || ! is_array($c)) {
                continue; // unknown or repeated ref: never post a conversation Claude made up
            }
            $seen[$ref] = true;
            $kind = in_array($c['kind'] ?? null, ['order', 'lead', 'support', 'other'], true) ? $c['kind'] : 'other';
            $result = in_array($c['result'] ?? null, ['won', 'open', 'lost', 'none'], true) ? $c['result'] : 'none';
            if ($kind === 'other') {
                $others[] = '• '.e($chat['customer']).(filled($c['summary'] ?? null) ? ' — '.$t($c['summary']) : '');

                continue;
            }
            $score = max(0, min(10, (int) ($c['score'] ?? 0)));
            $icon = ['won' => '🟢', 'open' => '🟡', 'lost' => '🔴', 'none' => '⚪'][$result];
            $lines = [
                $icon.' <b>'.e($chat['customer']).'</b>'.($chat['new'] ? ' · '.e($l('new')) : '').' · '.e(ChatConversation::CHANNELS[$chat['channel']] ?? $chat['channel']),
                e($this->label($lang, 'kind')[$kind]).($result !== 'none' ? ' · '.e($this->label($lang, 'result')[$result]) : ''),
                '👤 '.($t($c['handled_by'] ?? '') ?: e($l('nobody'))).' · ⭐ '.$score.'/10',
                '',
                $t($c['summary'] ?? ''),
            ];
            if (filled($c['next_step'] ?? null)) {
                $lines[] = "\n➡️ <b>".e($l('next')).':</b> '.$t($c['next_step']);
            }
            if ($result === 'lost' && filled($c['lost_reason'] ?? null)) {
                $lines[] = '❌ <b>'.e($l('lost')).':</b> '.$t($c['lost_reason']);
            }
            if (filled($c['score_comment'] ?? null)) {
                $lines[] = '💬 <i>'.$t($c['score_comment']).'</i>';
            }
            $lines[] = '<a href="'.e(route('admin.chat-conversations.show', $chat['conversation_id'])).'">'.e($l('open_chat')).'</a>';
            $messages[] = $this->fit(implode("\n", $lines));
        }
        foreach ($data['chats'] as $ref => $chat) {
            if (! isset($seen[$ref])) {
                $others[] = '• '.e($chat['customer']).' — ?';
            }
        }
        if ($others) {
            array_push($messages, ...$this->chunks('<b>'.e($l('other_chats')).'</b>', $others));
        }

        // The week summary, as few messages as fit.
        $tot = is_array($json['totals'] ?? null) ? $json['totals'] : [];
        $n = fn ($k) => (int) ($tot[$k] ?? 0);
        $summary = [
            '📊 <b>'.e($l('title')).'</b> · '.e($this->period($from, $to))."\n\n<b>".e($l('overview'))."</b>\n"
            .'• '.e($l('conversations')).': '.count($data['chats']).' · '.e($l('customers')).': '.$data['customers'].' ('.$data['new_customers'].' '.e($l('new_customers')).")\n"
            .'• 🎯 '.e($l('leads')).': '.$n('leads').' · '.e($l('callbacks')).': '.$data['leads']."\n"
            .'• 🛒 '.e($l('orders')).': '.$n('orders').' · '.e($l('won')).': '.$n('won')."\n"
            .'• ⏳ '.e($l('open')).': '.$n('open').' · ❌ '.e($l('lost_n')).': '.$n('lost')."\n"
            .'• '.e($l('conversion')).': <b>'.(is_numeric($tot['conversion_pct'] ?? null) ? (int) $tot['conversion_pct'].'%' : '—').'</b>'
            .($data['omitted'] ? "\n\n".e(str_replace(':count', (string) $data['omitted'], $l('omitted'))) : ''),
        ];
        $team = [];
        foreach (is_array($json['team'] ?? null) ? $json['team'] : [] as $m) {
            if (! is_array($m) || ! is_scalar($m['name'] ?? null) || ! filled($m['name'])) {
                continue;
            }
            $times = $data['reply_times'][trim((string) $m['name'])] ?? null; // name checked scalar above
            $team[] = '👤 <b>'.$t($m['name']).'</b> — ⭐ '.round(max(0, min(10, (float) ($m['avg_score'] ?? 0))), 1).'/10 · '
                .(int) ($m['conversations'] ?? 0).' · 🛒 '.(int) ($m['won'] ?? 0)
                .($times ? ' · '.e($l('reply')).' ~'.e($this->duration((int) round(array_sum($times) / count($times)), $lang)) : '')
                .(filled($m['strengths'] ?? null) ? "\n   ✅ ".$t($m['strengths']) : '')
                .(filled($m['improve'] ?? null) ? "\n   ⚠️ ".$t($m['improve']) : '');
        }
        if ($team) {
            $summary[] = '<b>'.e($l('team'))."</b>\n".implode("\n", $team);
        }
        foreach (['went_well' => 'went_well', 'problems' => 'problems', 'knowledge_gaps' => 'gaps'] as $field => $label) {
            $points = array_values(array_filter(array_map(fn ($p) => $t($p), is_array($json[$field] ?? null) ? array_slice($json[$field], 0, 5) : [])));
            if ($points) {
                $summary[] = '<b>'.e($l($label))."</b>\n• ".implode("\n• ", $points);
            }
        }
        $end = (filled($json['conclusion'] ?? null) ? '<b>'.e($l('conclusion')).':</b> '.$t($json['conclusion'], 1500) : '')
            .(filled($json['next_week_goal'] ?? null) ? "\n🎯 <b>".e($l('goal')).':</b> '.$t($json['next_week_goal']) : '');
        $summary[] = trim($end."\n\n<i>Claude Opus 5.5 · ".e(ClaudePricing::format($cost)).'</i>');

        return array_merge($messages, $this->chunks(null, $summary, "\n\n"));
    }

    /** Joins parts into as few messages as fit Telegram's limit (each part shortened if alone too long). */
    private function chunks(?string $head, array $parts, string $glue = "\n"): array
    {
        $out = [];
        $current = $head ?? '';
        foreach ($parts as $part) {
            $part = $this->fit($part);
            if ($current !== '' && mb_strlen($current.$glue.$part) > self::TELEGRAM_MAX) {
                $out[] = $current;
                $current = $part;
            } else {
                $current = $current === '' ? $part : $current.($current === $head ? "\n" : $glue).$part;
            }
        }
        if (trim($current) !== '') {
            $out[] = $current;
        }

        return $out;
    }

    /** One message under Telegram's limit; cut on a line break so no HTML tag is left open. */
    private function fit(string $html): string
    {
        if (mb_strlen($html) <= self::TELEGRAM_MAX) {
            return $html;
        }
        // Plain text (everything in it is already escaped): drop the tags rather than leave one open,
        // and never end inside an entity such as &amp;.
        $cut = mb_substr(strip_tags($html), 0, self::TELEGRAM_MAX - 2);

        return preg_replace('/&[#a-z0-9]*$/i', '', $cut).'…';
    }

    /** @return list<int|null> message IDs (null where sending failed) */
    private function send(string $chat, array $messages): array
    {
        $delay = (int) config('services.telegram.report_delay_ms', 3100); // a group takes about 20 messages a minute
        $ids = [];
        foreach ($messages as $i => $html) {
            if ($i > 0 && $delay > 0) {
                usleep($delay * 1000);
            }
            $params = ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
            $sent = $this->telegram->call('sendMessage', $params);
            if (! $sent && $delay > 0) {
                usleep(max($delay, 5000) * 1000); // rate limit or a hiccup: one more try
                $sent = $this->telegram->call('sendMessage', $params);
            }
            $ids[] = $sent ? ((int) ($sent['message_id'] ?? 0) ?: null) : null;
        }

        return $ids;
    }

    private function label(string $lang, string $key): mixed
    {
        return self::LABELS[$lang][$key] ?? self::LABELS['en'][$key];
    }

    public function period(Carbon $from, Carbon $to): string
    {
        return $from->copy()->setTimezone(self::TZ)->format('d.m.Y H:i').' – '.$to->copy()->setTimezone(self::TZ)->format('d.m.Y H:i');
    }

    private function duration(int $seconds, string $lang = 'en'): string
    {
        $min = (int) round($seconds / 60);

        return $min < 60 ? max(1, $min).' '.$this->label($lang, 'min') : round($min / 60, 1).' '.$this->label($lang, 'h');
    }
}
