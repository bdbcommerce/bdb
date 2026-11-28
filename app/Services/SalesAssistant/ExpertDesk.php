<?php

namespace App\Services\SalesAssistant;

use App\Models\CatalogClient;
use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\ExpertQuestion;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\SalesKnowledge;
use App\Models\TelegramUser;
use App\Services\Ai\VoiceTranscriber;
use App\Services\AiErrorAlerts;
use App\Services\SalesReport\SalesReporter;
use App\Services\Seo\SeoFiles;
use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Questions from the AI to people, in the bot chat (Admin → AI team → Expert questions). The AI passes on what it
 * cannot answer — or is not sure of, with its own idea of the answer — to an employee with "Answers AI questions" in
 * the question's department. Each expert has one question at a time: the next goes out only after the current one is
 * answered (text or voice), transferred to another department (max MAX_TRANSFERS, then the main admin) or marked
 * "don't know". Working time only: a reminder after expert_remind_minutes, the main admin after
 * expert_escalate_minutes. The customer gets the answer at once, in the agent's words (SalesAssistant::expertAnswer);
 * the answer is saved as a knowledge draft for sales to approve.
 */
class ExpertDesk
{
    /** A customer who wrote this recently is waiting live: their questions go first. */
    private const LIVE_MINUTES = 30;

    /** An expert's bot chat that refused a message is skipped this long. */
    private const UNREACHABLE_MINUTES = 60;

    /** Open questions per chat, and questions per chat a day; more are left to the managers (Client requests). */
    public const PER_CHAT_OPEN = 3;

    public const PER_CHAT_DAILY = 10;

    /** The AI's idea is stored (and accepted) exactly as shown to the expert. */
    public const IDEA_MAX = 1500;

    /** Voice answers: per expert an hour, longest (seconds) and largest file. */
    private const VOICE_PER_HOUR = 20;

    private const VOICE_MAX_SECONDS = 180;

    private const VOICE_MAX_BYTES = 3 * 1024 * 1024;

    public function __construct(private TelegramApi $api, private VoiceTranscriber $transcriber, private AiErrorAlerts $alerts) {}

    /** Somebody can take questions (an expert or the main admin). */
    public static function available(): bool
    {
        // Only people the bot can write to (they pressed /start): otherwise the old source-group note.
        return (bool) rescue(fn () => ExpertQuestion::experts()->contains(fn (TelegramUser $u) => $u->bot_chat_at !== null), false, false);
    }

    /**
     * A new question from a turn. The same open question of this chat is not asked twice; the same topic asked by
     * another customer joins the open one (one answer for all). Null when this chat already has PER_CHAT_OPEN open
     * questions or PER_CHAT_DAILY today (one customer cannot fill the experts' queue).
     */
    public function ask(ChatConversation $conversation, ?TelegramUser $customer, string $question, string $draft, string $topic, string $kind, string $department, string $customerText, ?ClientRequest $request, string $language): ?ExpertQuestion
    {
        $topic = (string) ClientRequest::normalizeTopic($topic !== '' ? $topic : mb_substr($question, 0, 60));
        $departments = ExpertQuestion::departments();
        $department = collect($departments)->first(fn ($d) => mb_strtolower($d) === mb_strtolower(trim($department)));
        $chat = ChatConversation::query()->sameChatAs($conversation)->pluck('id');
        $open = ExpertQuestion::whereIn('status', [...ExpertQuestion::OPEN, 'grouped'])->where('topic', $topic)->where('created_at', '>=', now()->subDays(7))->orderBy('id')->get();
        if ($same = $open->first(fn (ExpertQuestion $q) => $chat->contains($q->chat_conversation_id))) {
            return $same;
        }
        $mine = ExpertQuestion::whereIn('chat_conversation_id', $chat);
        if ((clone $mine)->whereIn('status', [...ExpertQuestion::OPEN, 'grouped'])->count() >= self::PER_CHAT_OPEN
            || (clone $mine)->where('created_at', '>=', now()->subDay())->count() >= self::PER_CHAT_DAILY) {
            return null;
        }
        $parent = $open->first(fn (ExpertQuestion $q) => $q->parent_id === null && in_array($q->status, ExpertQuestion::OPEN, true));
        // Phones are hidden from experts (as in the sales report); the idea is kept exactly as the expert will see it.
        $mask = fn (string $t) => app(SalesReporter::class)->maskPhones($t);

        $q = ExpertQuestion::create([
            'chat_conversation_id' => $conversation->id, 'customer_id' => $customer?->id, 'parent_id' => $parent?->id, 'client_request_id' => $request?->id,
            'question' => $mask(mb_substr($question, 0, 1000)), 'customer_text' => $mask(mb_substr($customerText, 0, 1000)) ?: null,
            'ai_draft' => $mask(mb_substr(trim($draft), 0, self::IDEA_MAX)) ?: null,
            'topic' => $topic ?: null, 'knowledge_kind' => isset(SalesKnowledge::KINDS[$kind]) ? $kind : null, 'department' => $department,
            'language' => in_array($language, ['uz', 'ru', 'en'], true) ? $language : 'ru', 'status' => $parent ? 'grouped' : 'queued',
            'history' => [['at' => now()->toIso8601String(), 'event' => $parent ? "grouped with #{$parent->id}" : 'asked by the AI']],
        ]);
        // The parent may have been answered (or closed) while this row was made: follow it instead of waiting forever.
        $state = $parent?->fresh()?->status;
        if ($parent && $state === 'answered') {
            $q->update(['status' => 'answered', 'answer' => $parent->fresh()->answer, 'verdict' => $parent->fresh()->verdict, 'answered_by' => $parent->fresh()->answered_by,
                'answered_at' => now(), 'sales_knowledge_id' => $parent->fresh()->sales_knowledge_id]);
            $this->deliver($q->refresh());
        } elseif ($parent && ! in_array($state, ExpertQuestion::OPEN, true)) {
            $q->update(['status' => 'queued', 'parent_id' => null]);
            $this->dispatch();
        } else {
            $parent ? $this->tellGrouped($parent) : $this->dispatch();
        }

        return $q;
    }

    /** Sends queued questions to free experts (working time only). Returns how many were sent. */
    public function dispatch(): int
    {
        $settings = SalesAssistantSetting::current();
        if (! $settings->isWorkingTime()) {
            return 0;
        }
        $lock = Cache::lock('expert-desk:dispatch', 60);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $experts = ExpertQuestion::experts();
            if ($experts->isEmpty()) {
                return 0;
            }
            $sent = 0;
            $busy = ExpertQuestion::where('status', 'asked')->pluck('expert_id')->filter()->all();
            $queued = ExpertQuestion::where('status', 'queued')->orderBy('id')->limit(200)->get()
                ->sortBy(fn (ExpertQuestion $q) => [$this->live($q) ? 0 : 1, $q->to_admin ? 0 : 1, $q->id])->values();
            foreach ($queued as $q) {
                $expert = $this->pick($q, $experts, $busy);
                if ($expert && $this->send($q, $expert)) {
                    $busy[] = $expert->id;
                    $sent++;
                }
            }

            return $sent;
        } finally {
            $lock->release();
        }
    }

    /** The customer wrote recently (a live chat). */
    private function live(ExpertQuestion $q): bool
    {
        $last = collect($q->conversation?->messages ?? [])->reverse()->first(fn ($m) => is_array($m) && ($m['role'] ?? null) === 'user');

        return isset($last['at']) && (bool) rescue(fn () => Carbon::parse($last['at'])->gte(now()->subMinutes(self::LIVE_MINUTES)), false, false);
    }

    /**
     * Who gets this question now: a free expert of its department (not one who passed it on), else — when the
     * department has nobody — the main admin, else any free expert. Null = wait.
     *
     * @param  Collection<int, TelegramUser>  $experts
     */
    private function pick(ExpertQuestion $q, Collection $experts, array $busy): ?TelegramUser
    {
        $declined = array_map('intval', (array) ($q->declined_by ?? []));
        // Reachable: pressed /start and the last send did not fail. Free: reachable and holding no question.
        $reachable = fn (TelegramUser $u) => $u->bot_chat_at !== null && ! Cache::has('expert-unreachable:'.$u->id);
        $free = fn (TelegramUser $u) => $reachable($u) && ! in_array($u->id, $busy, true);
        $admin = ExpertQuestion::mainAdmin();
        if (! $q->to_admin) {
            $department = $experts->filter(fn (TelegramUser $u) => $u->answers_questions && $q->department !== null && $u->employee_role === $q->department
                && ! in_array($u->id, $declined, true) && $reachable($u));
            if ($department->isNotEmpty()) {
                return $department->first($free); // somebody there can take it: wait for them
            }
        }
        if ($admin && $reachable($admin)) {
            return $free($admin) ? $admin : null;
        }

        return $experts->first(fn (TelegramUser $u) => $u->answers_questions && $free($u) && ! in_array($u->id, $declined, true))
            ?? $experts->first(fn (TelegramUser $u) => $u->answers_questions && $free($u));
    }

    private function t(string $key, TelegramUser $expert, array $replace = []): string
    {
        return __('expert.'.$key, $replace, in_array($expert->locale, ['uz', 'ru', 'en'], true) ? $expert->locale : 'ru');
    }

    /** The question in the expert's bot chat, with the buttons. */
    private function send(ExpertQuestion $q, TelegramUser $expert): bool
    {
        if (! ExpertQuestion::whereKey($q->id)->where('status', 'queued')->update(['status' => 'asked', 'expert_id' => $expert->id])) {
            return false; // taken meanwhile
        }
        $q->refresh();
        $e = fn ($v) => e((string) $v);
        $others = $q->children()->where('status', 'grouped')->count();
        // Telegram allows 4096 characters (UTF-16, visible text): the customer's text is shortened first; the idea stays whole.
        $build = fn (int $room) => [$this->t('question_title', $expert, ['id' => $q->id]).($q->department ? ' · '.$e($q->department) : ''),
            ...($q->to_admin ? ['<i>'.$this->t($q->escalated_at ? 'escalated_note' : 'to_admin_note', $expert).'</i>'] : []), '',
            '<b>'.$this->t('customer', $expert).':</b> '.$e(mb_strimwidth((string) ($q->customer?->displayName() ?? '—'), 0, 80, '…')),
            ...($q->customer_text ? ['<b>'.$this->t('customer_wrote', $expert).':</b> «'.$e(mb_strimwidth($q->customer_text, 0, $room, '…')).'»'] : []),
            '<b>'.$this->t('question', $expert).':</b> '.$e(mb_strimwidth($q->question, 0, 1000, '…')),
            // The customer's price group: an expert quoting a price must use theirs (dealer prices never to an end user).
            '<b>'.$this->t('price_group', $expert).':</b> '.$this->t('group_'.rescue(fn () => CatalogClient::priceGroupFor($q->customer), 'end_user', false), $expert),
            // Shown whole: "Correct" accepts exactly this text (stored at most IDEA_MAX).
            ...($q->ai_draft ? ['', '<b>'.$this->t('ai_idea', $expert).':</b> '.$e($q->ai_draft)] : []),
            ...($q->note ? ['', '<b>'.$this->t('note', $expert).':</b> '.$e(mb_strimwidth($q->note, 0, 500, '…'))] : []),
            ...($others ? ['', '<i>'.$this->t('others', $expert, ['count' => $others]).'</i>'] : []),
            '', $this->t($q->ai_draft ? 'how_with_idea' : 'how', $expert), '<i>'.$this->t('generic', $expert).'</i>'];
        foreach ([1000, 500, 200, 50] as $room) {
            $lines = $build($room);
            $visible = html_entity_decode(strip_tags(implode("\n", $lines)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strlen(mb_convert_encoding($visible, 'UTF-16LE', 'UTF-8')) / 2 <= 4000) {
                break;
            }
        }
        $buttons = [
            ...($q->ai_draft ? [[['text' => $this->t('btn_accept', $expert), 'callback_data' => "xq:{$q->id}:accept"], ['text' => $this->t('btn_fix', $expert), 'callback_data' => "xq:{$q->id}:fix"]]] : []),
            [['text' => $this->t('btn_transfer', $expert), 'callback_data' => "xq:{$q->id}:transfer"], ['text' => $this->t('btn_unknown', $expert), 'callback_data' => "xq:{$q->id}:unknown"]],
        ];
        $result = $this->api->call('sendMessage', ['chat_id' => $expert->telegram_id, 'text' => implode("\n", $lines), 'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true], 'reply_markup' => ['inline_keyboard' => $buttons]]);
        if ($result === null) {
            // Usually: the expert never pressed /start in the bot. The question goes back to the queue.
            ExpertQuestion::whereKey($q->id)->update(['status' => 'queued', 'expert_id' => null]);
            Cache::put('expert-unreachable:'.$expert->id, true, now()->addMinutes(self::UNREACHABLE_MINUTES));
            $this->alerts->report('Expert questions: cannot write to '.($expert->displayName() ?? $expert->telegram_id), 'The bot could not send them a question. They must open the bot and press /start.', 'Sales assistant');

            return false;
        }
        $q->message_id = is_numeric($result['message_id'] ?? null) ? (int) $result['message_id'] : null;
        $q->asked_at = now();
        $q->reminded_at = null;
        $q->log('sent to '.($expert->displayName() ?? $expert->telegram_id));
        $q->save();

        return true;
    }

    /** The expert working on a question is told that another customer asks the same. */
    private function tellGrouped(ExpertQuestion $parent): void
    {
        if ($parent->status === 'asked' && $parent->expert) {
            $this->say($parent->expert, $this->t('grouped', $parent->expert, ['id' => $parent->id, 'count' => $parent->children()->where('status', 'grouped')->count() + 1]), $parent->message_id);
        }
    }

    private function say(TelegramUser $expert, string $text, ?int $replyTo = null, array $buttons = []): ?array
    {
        return $this->api->call('sendMessage', ['chat_id' => $expert->telegram_id, 'text' => $text, 'parse_mode' => 'HTML']
            + ($replyTo ? ['reply_parameters' => ['message_id' => $replyTo, 'allow_sending_without_reply' => true]] : [])
            + ($buttons ? ['reply_markup' => ['inline_keyboard' => $buttons]] : []));
    }

    /** The expert's current question (one at a time). */
    private function current(TelegramUser $expert): ?ExpertQuestion
    {
        return ExpertQuestion::where('status', 'asked')->where('expert_id', $expert->id)->latest('id')->first();
    }

    /**
     * A message from an expert in the bot chat. Returns true when it was theirs to handle (the answer, a transfer
     * note, a corrected transcript, /start); false leaves it to the usual bot flow.
     */
    public function message(TelegramUser $expert, array $message): bool
    {
        if (! $expert->isExpert()) {
            return false;
        }
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
        $q = $this->current($expert);
        if (str_starts_with($text, '/start')) {
            $this->say($expert, $this->t($q ? 'start_busy' : 'start', $expert, ['id' => $q?->id]));
            $q || $this->dispatch();

            return true;
        }
        if (! $q) {
            return false;
        }
        $pending = (array) ($q->pending ?? []);
        $voice = is_array($message['voice'] ?? null) ? $message['voice'] : (is_array($message['audio'] ?? null) ? $message['audio'] : null);
        if ($voice) {
            $this->voice($expert, $q, $voice);

            return true;
        }
        if ($text === '' || str_starts_with($text, '/')) {
            $this->say($expert, $this->t('text_or_voice', $expert));

            return true;
        }
        $text = mb_substr($text, 0, 3000);
        if (($pending['action'] ?? null) === 'note') {
            $this->transfer($q, $expert, (string) ($pending['to'] ?? 'admin'), $text);

            return true;
        }
        $this->finish($q, $expert, $text, $q->ai_draft ? 'fixed' : 'written');

        return true;
    }

    /** A voice answer: transcribed, then the expert sends it as is or corrects it. */
    private function voice(TelegramUser $expert, ExpertQuestion $q, array $voice): void
    {
        $seconds = is_numeric($voice['duration'] ?? null) ? (int) $voice['duration'] : 0;
        if ($seconds > self::VOICE_MAX_SECONDS || ! RateLimiter::attempt('expert-voice:'.$expert->id, self::VOICE_PER_HOUR, fn () => true, 3600)) {
            $this->say($expert, $this->t('voice_failed', $expert));

            return;
        }
        $file = is_string($voice['file_id'] ?? null) ? $this->api->downloadFile($voice['file_id'], self::VOICE_MAX_BYTES) : null;
        $name = is_string($voice['file_name'] ?? null) && preg_match('/\.(mp3|m4a|wav|ogg|oga|opus|webm|flac)$/i', $voice['file_name'], $ext) ? 'voice.'.strtolower($ext[1]) : 'voice.ogg';
        // The real length of Telegram voice is read from the file; a paid transcriber gets nothing it cannot measure.
        $real = $file ? VoiceTranscriber::oggSeconds($file['body']) : null;
        if ($file && (($real !== null && $real > self::VOICE_MAX_SECONDS + 1) || ($real === null && $this->transcriber->paid()))) {
            $file = null;
        }
        $result = $file ? $this->transcriber->transcribeVoice(
            ['file_id' => $voice['file_id'], 'file_unique_id' => $voice['file_unique_id'] ?? null, 'telegram_id' => $expert->telegram_id, 'source' => 'expert'],
            $file['body'], $name,
        ) : null;
        $text = is_array($result) ? trim((string) ($result['text'] ?? '')) : '';
        if ($text === '' || VoiceTranscriber::isHallucination($text)) {
            $this->say($expert, $this->t('voice_failed', $expert));

            return;
        }
        $q->update(['pending' => ['action' => 'confirm', 'text' => mb_substr($text, 0, 3000)]]);
        $this->say($expert, $this->t('voice_heard', $expert)."\n\n«".e(mb_substr($text, 0, 3000)).'»', null, [[
            ['text' => $this->t('btn_send', $expert), 'callback_data' => "xq:{$q->id}:send"],
            ['text' => $this->t('btn_edit', $expert), 'callback_data' => "xq:{$q->id}:edit"],
        ]]);
    }

    /** A button under a question (callback data xq:{id}:{action}[:{arg}]). */
    public function callback(array $query): void
    {
        $id = is_scalar($query['id'] ?? null) ? (string) $query['id'] : null;
        $data = is_string($query['data'] ?? null) ? $query['data'] : '';
        $from = is_array($query['from'] ?? null) && is_scalar($query['from']['id'] ?? null) ? (string) $query['from']['id'] : '';
        // Only our own buttons, pressed by an expert, get any answer (other buttons are ignored without a call).
        if (! preg_match('/^xq:(\d+):([a-z]+)(?::(\w+))?$/', $data, $m) || $from === '') {
            return;
        }
        $expert = TelegramUser::where('telegram_id', $from)->first();
        $q = ExpertQuestion::find((int) $m[1]);
        if (! $expert || ! $expert->isExpert() || ! $q) {
            return;
        }
        $id && $this->api->call('answerCallbackQuery', ['callback_query_id' => $id]);
        // Only the expert the question is with now; a button under an old question says where it went.
        if ($q->status !== 'asked' || $q->expert_id !== $expert->id) {
            $this->say($expert, $this->t('not_yours', $expert, ['id' => $q->id]));

            return;
        }
        $pending = (array) ($q->pending ?? []);
        match ($m[2]) {
            'accept' => $q->ai_draft ? $this->finish($q, $expert, $q->ai_draft, 'accepted') : null,
            'fix' => $this->say($expert, $this->t('fix_prompt', $expert), $q->message_id),
            'send' => ($pending['action'] ?? null) === 'confirm' ? $this->finish($q, $expert, (string) $pending['text'], $q->ai_draft ? 'fixed' : 'written') : null,
            'edit' => tap($q)->update(['pending' => null]) && $this->say($expert, $this->t('edit_prompt', $expert)),
            'unknown' => $this->unknown($q, $expert),
            'transfer' => $this->chooseDepartment($q, $expert),
            'to' => $this->askNote($q, $expert, (string) ($m[3] ?? '')),
            'skip' => ($pending['action'] ?? null) === 'note' ? $this->transfer($q, $expert, (string) ($pending['to'] ?? 'admin'), null) : null,
            'back' => tap($q)->update(['pending' => null]) && $this->say($expert, $this->t('transfer_cancelled', $expert, ['id' => $q->id])),
            default => null,
        };
    }

    private function chooseDepartment(ExpertQuestion $q, TelegramUser $expert): void
    {
        // A short hash of the name, not a position: the button keeps its department when roles change meanwhile.
        $rows = collect(ExpertQuestion::departments())->map(fn ($d) => $d === $expert->employee_role ? null : [['text' => $d, 'callback_data' => "xq:{$q->id}:to:".self::code($d)]])->filter()->values()->all();
        $admin = ExpertQuestion::mainAdmin();
        if ($admin && $admin->id !== $expert->id) {
            $rows[] = [['text' => $this->t('main_admin', $expert), 'callback_data' => "xq:{$q->id}:to:admin"]];
        }
        if (! $rows) {
            $this->say($expert, $this->t('nobody_to_transfer', $expert));

            return;
        }
        $this->say($expert, $this->t('transfer_to', $expert, ['id' => $q->id]), null, $rows);
    }

    /** The department in a button's callback data (10 hex characters). */
    public static function code(string $department): string
    {
        return substr(md5($department), 0, 10);
    }

    private function askNote(ExpertQuestion $q, TelegramUser $expert, string $to): void
    {
        $target = $to === 'admin' ? 'admin' : collect(ExpertQuestion::departments())->first(fn ($d) => self::code($d) === $to);
        if ($target === null) {
            return;
        }
        $q->update(['pending' => ['action' => 'note', 'to' => $target]]);
        $this->say($expert, $this->t('note_prompt', $expert, ['to' => $target === 'admin' ? $this->t('main_admin', $expert) : e($target)]), null,
            [[['text' => $this->t('btn_skip', $expert), 'callback_data' => "xq:{$q->id}:skip"], ['text' => $this->t('btn_back', $expert), 'callback_data' => "xq:{$q->id}:back"]]]);
    }

    /** An admin cancelled the question: the expert is told and is free for the next one. */
    public function cancelled(ExpertQuestion $q, ?TelegramUser $expert): void
    {
        $expert && $this->say($expert, $this->t('cancelled', $expert, ['id' => $q->id]), $q->message_id);
        $this->dispatch();
    }

    /** Passes the question to another department (or the main admin); after MAX_TRANSFERS it goes to the main admin. */
    private function transfer(ExpertQuestion $q, TelegramUser $expert, string $to, ?string $note): void
    {
        $toAdmin = $to === 'admin' || ($q->transfers + 1 >= ExpertQuestion::MAX_TRANSFERS && ExpertQuestion::mainAdmin());
        $fresh = ExpertQuestion::whereKey($q->id)->where('status', 'asked')->where('expert_id', $expert->id)->update(['status' => 'queued', 'expert_id' => null]);
        if (! $fresh) {
            return;
        }
        $q->refresh();
        $q->fill(['department' => $to === 'admin' ? $q->department : $to, 'to_admin' => $toAdmin, 'transfers' => $q->transfers + 1, 'pending' => null,
            'note' => $note !== null ? mb_substr($note, 0, 1000) : null, 'declined_by' => array_values(array_unique([...(array) ($q->declined_by ?? []), $expert->id])), 'asked_at' => null]);
        $q->log('transferred to '.($toAdmin ? 'the main admin' : $to), $expert, $note ? ['note' => $note] : []);
        $q->save();
        $this->say($expert, $this->t('transferred', $expert, ['id' => $q->id, 'to' => $toAdmin ? $this->t('main_admin', $expert) : e($to)]));
        $this->dispatch();
    }

    /** "Don't know": the managers take it (the old note to the source group); the client request stays open. */
    private function unknown(ExpertQuestion $q, TelegramUser $expert): void
    {
        if (! ExpertQuestion::whereKey($q->id)->where('status', 'asked')->where('expert_id', $expert->id)->update(['status' => 'unknown'])) {
            return;
        }
        $q->refresh();
        $q->fill(['verdict' => 'unknown', 'answered_by' => $expert->id, 'answered_at' => now(), 'pending' => null]);
        $q->log('did not know', $expert);
        $q->save();
        foreach ($q->family() as $one) {
            if ($one->id !== $q->id) {
                $one->update(['status' => 'unknown', 'verdict' => 'unknown', 'answered_by' => $expert->id, 'answered_at' => now()]);
            }
            $this->toManagers($one, null);
        }
        $this->say($expert, $this->t('unknown_done', $expert, ['id' => $q->id]));
        $this->dispatch();
    }

    /**
     * The answer: saved (the training record), a knowledge draft for sales to approve, and passed to every customer
     * who asked, in the agent's words.
     */
    public function finish(ExpertQuestion $q, TelegramUser $expert, string $answer, string $verdict): void
    {
        $answer = trim(mb_substr($answer, 0, 3000));
        if ($answer === '' || ! ExpertQuestion::whereKey($q->id)->where('status', 'asked')->where('expert_id', $expert->id)->update(['status' => 'answered'])) {
            return;
        }
        $q->refresh();
        $knowledge = rescue(fn () => SalesKnowledge::create([
            'kind' => $q->knowledge_kind ?? 'product', 'title' => mb_substr($q->topic ?: $q->question, 0, 120),
            // No staff names: approved knowledge reaches customers (who answered is in the expert question).
            'body' => 'Question: '.$q->question."\nAnswer (our specialist, ".now()->setTimezone(SalesAssistantSetting::TZ)->format('d.m.Y').'): '.$answer,
            'is_active' => false, 'sort' => 1000,
        ]), null);
        $q->fill(['answer' => $answer, 'verdict' => $verdict, 'answered_by' => $expert->id, 'answered_at' => now(), 'pending' => null, 'sales_knowledge_id' => $knowledge?->id]);
        $q->log(ExpertQuestion::VERDICTS[$verdict] ?? $verdict, $expert);
        $q->save();
        $family = $q->family();
        foreach ($family as $one) {
            if ($one->id !== $q->id) {
                $one->update(['status' => 'answered', 'answer' => $answer, 'verdict' => $verdict, 'answered_by' => $expert->id, 'answered_at' => now(), 'sales_knowledge_id' => $knowledge?->id]);
            }
            if ($one->client_request_id) {
                ClientRequest::whereKey($one->client_request_id)->where('status', 'open')->update(['status' => 'resolved', 'resolved_at' => now(), 'sales_knowledge_id' => $knowledge?->id]);
            }
        }
        $this->say($expert, $this->t('thanks', $expert, ['count' => $family->count()]));
        foreach ($family as $one) {
            $this->deliver($one->refresh());
        }
        $this->dispatch();
    }

    /** Passes an answer to its customer (also retried by the tick while the chat is busy). */
    public function deliver(ExpertQuestion $q): void
    {
        if ($q->status !== 'answered' || $q->delivered !== null) {
            return;
        }
        $how = app(SalesAssistant::class)->expertAnswer($q);
        if ($how === 'busy') {
            return; // a turn is running in that chat: the next tick tries again
        }
        $how === 'manager' && $this->toManagers($q, $q->answer);
        $q->update(['delivered' => $how]);
    }

    /** The question (and the answer, if any) to the invoice parser's source group, for a manager to reply. */
    public function toManagers(ExpertQuestion $q, ?string $answer): void
    {
        $chat = trim((string) InvoiceParserSetting::current()->source_chat_id);
        $conversation = $q->conversation;
        if ($chat === '' || ! $this->api->configured() || ! $conversation) {
            Log::notice('Expert questions: nothing sent to the managers (no source group or chat)', ['question' => $q->id]);

            return;
        }
        $who = $q->customer?->displayName() ?? 'Telegram '.$conversation->external_id;
        $head = $answer !== null ? '💬 <b>The expert answered, but the assistant could not pass it on — please send it to the customer</b>'
            : '❓ <b>The assistant could not answer — please reply to the customer</b>';
        $this->api->sendMessage($chat, $head."\n\n<b>Customer:</b> ".e($who).($q->customer?->username ? ' (@'.e($q->customer->username).')' : '')
            ."\n<b>Question:</b> ".e(mb_strimwidth($q->question, 0, 1000, '…'))
            .($answer !== null ? "\n<b>Answer:</b> ".e(mb_strimwidth($answer, 0, 2000, '…')) : '')
            ."\n\n<a href=\"".e(SeoFiles::publicUrl(route('admin.chat-conversations.show', $conversation))).'">Open the chat</a>',
            ['parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]]);
    }

    /**
     * Every minute: send queued questions, remind experts, pass silent questions to the main admin, tell the managers
     * about a question nobody answered, and retry answers not delivered yet. Returns counts.
     *
     * @return array{sent: int, reminded: int, escalated: int, delivered: int}
     */
    public function tick(): array
    {
        $settings = SalesAssistantSetting::current();
        $out = ['sent' => 0, 'reminded' => 0, 'escalated' => 0, 'delivered' => 0];
        ExpertQuestion::where('status', 'answered')->whereNull('delivered')->where('answered_at', '>=', now()->subDays(4))->orderBy('id')->limit(20)->get()
            ->each(function (ExpertQuestion $q) use (&$out) {
                $this->deliver($q);
                $q->refresh()->delivered !== null && $out['delivered']++;
            });
        if (! $settings->isWorkingTime()) {
            return $out;
        }
        $admin = ExpertQuestion::mainAdmin();
        foreach (ExpertQuestion::where('status', 'asked')->whereNotNull('asked_at')->with('expert')->orderBy('id')->get() as $q) {
            $minutes = $settings->workingMinutesBetween($q->asked_at, now());
            $expert = $q->expert;
            if (! $expert) {
                $q->update(['status' => 'queued', 'expert_id' => null]);

                continue;
            }
            if ($minutes >= $settings->expertEscalateMinutes() && $admin && $admin->id !== $expert->id) {
                if (ExpertQuestion::whereKey($q->id)->where('status', 'asked')->where('expert_id', $expert->id)->update(['status' => 'queued', 'expert_id' => null])) {
                    $q->refresh();
                    $q->fill(['to_admin' => true, 'escalated_at' => now(), 'pending' => null, 'asked_at' => null,
                        'declined_by' => array_values(array_unique([...(array) ($q->declined_by ?? []), $expert->id]))]);
                    $q->log('no answer in '.$settings->expertEscalateMinutes().' working minutes: to the main admin');
                    $q->save();
                    $this->say($expert, $this->t('escalated', $expert, ['id' => $q->id, 'minutes' => $settings->expertEscalateMinutes()]), $q->message_id);
                    $out['escalated']++;
                }
            } elseif ($minutes >= $settings->expertRemindMinutes() && ! $q->reminded_at) {
                $q->update(['reminded_at' => now()]);
                $this->say($expert, $this->t('reminder', $expert, ['id' => $q->id]), $q->message_id);
                $out['reminded']++;
            }
        }
        // Waiting in the queue that long (nobody in the department free or reachable): the main admin takes it.
        if ($admin) {
            foreach (ExpertQuestion::where('status', 'queued')->where('to_admin', false)->orderBy('id')->get() as $q) {
                if ($settings->workingMinutesBetween($q->created_at, now()) >= $settings->expertEscalateMinutes()) {
                    $q->to_admin = true;
                    $q->escalated_at = now();
                    $q->log('waited '.$settings->expertEscalateMinutes().' working minutes in the queue: to the main admin');
                    $q->save();
                    $out['escalated']++;
                }
            }
        }
        // Nobody has answered in twice the escalation time (e.g. the main admin is silent too): the managers are told once.
        foreach (ExpertQuestion::open()->whereNull('fallback_at')->where('created_at', '>=', now()->subDays(7))->orderBy('id')->get() as $q) {
            if ($settings->workingMinutesBetween($q->created_at, now()) >= 2 * $settings->expertEscalateMinutes()) {
                $q->update(['fallback_at' => now()]);
                foreach ($q->family() as $one) {
                    $this->toManagers($one, null);
                }
            }
        }
        $out['sent'] = $this->dispatch();

        return $out;
    }
}
