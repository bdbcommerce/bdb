<?php

namespace App\Services\Telegram;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ClientRequest;
use App\Models\TelegramUser;
use App\Services\ChatAssistant;
use App\Services\Invoices\InvoiceParser;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The website assistant as a Telegram bot. Handles one webhook update.
 *
 * A new customer first registers: picks a language, types a name and shares the
 * phone number linked to their Telegram account. Only then are text questions
 * answered by ChatAssistant (briefly). Other messages (photos, voice, files…) are
 * not answered; only their type and Telegram file ID are stored in the conversation.
 * Only private chats are answered, and only with TELEGRAM_BOT_REPLIES=true (otherwise
 * they are just recorded). Telegram Business chats (a Premium account that connected
 * the bot) and groups are recorded without replies by TelegramRecorder.
 */
class TelegramBot
{
    /** A chat idle longer than this starts a new conversation. */
    private const SESSION_HOURS = 24;

    /** Language picker buttons; the picker prompt is shown in all three languages. */
    public const LANGUAGES = ['uz' => '🇺🇿 Oʻzbekcha', 'ru' => '🇷🇺 Русский', 'en' => '🇬🇧 English'];

    public const LANGUAGE_PROMPT = '🌐 Tilni tanlang · Выберите язык · Choose a language';

    /** Message keys that carry a downloadable file, checked in this order (an animation also has "document"). */
    public const FILE_TYPES = ['animation', 'photo', 'video', 'video_note', 'voice', 'audio', 'sticker', 'document'];

    /** Other non-text message kinds, stored by type only. */
    public const OTHER_TYPES = ['contact', 'location', 'venue', 'poll', 'dice', 'story'];

    public function __construct(
        private TelegramApi $api,
        private ChatAssistant $assistant,
        private TelegramNotifier $notifier,
        private TelegramRecorder $recorder,
    ) {}

    /** Telegram ID of the private message being handled (to mark it answered). */
    private ?int $messageId = null;

    public function handle(array $update): void
    {
        // The invoice source chat belongs to the invoice parser (Admin → Invoice parser): photos are read
        // by Claude, everything else there (text, edits) is ignored and never recorded or answered.
        foreach (['message', 'edited_message'] as $key) {
            if (is_array($part = $update[$key] ?? null) && ($parser = app(InvoiceParser::class))->handles($part)) {
                if ($key === 'message') {
                    // Only stored here: `invoices:process` (every minute, one at a time) reads it, so a flood of
                    // photos never ties up the web workers that serve the site.
                    $parser->capture($part);
                }

                return;
            }
        }

        if ($this->record($update)) {
            return;
        }

        $private = fn ($m) => is_array($m) && is_array($m['chat'] ?? null) && is_scalar($m['chat']['id'] ?? null)
            && ($m['chat']['type'] ?? null) === 'private';

        if ($private($edited = $update['edited_message'] ?? null)) {
            $this->recordEdit($edited);

            return;
        }

        $message = $update['message'] ?? null;
        if (! $private($message)) {
            return;
        }

        $chatId = $message['chat']['id'];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        // Malformed text (not a string) is treated as a non-text message.
        if (isset($message['text']) && ! is_scalar($message['text'])) {
            unset($message['text']);
        }

        // The customer is created from their chat info; the private chat ID is their user ID.
        $user = TelegramUser::fromTelegram(['id' => $chatId] + $from, 'bot');

        $locale = $user->locale ?? 'ru';
        $replies = (bool) config('services.telegram.replies');
        // Record-only mode, but an admin ticked "ask for registration info" for this customer.
        $asking = ! $replies && $user->ask_registration && ! $user->isRegistered();

        // Every message is recorded: text as text, anything else by type and Telegram file ID.
        $messageId = is_numeric($message['message_id'] ?? null) ? (int) $message['message_id'] : null;
        $this->conversation($user)->appendIncoming(
            TelegramRecorder::describe($message) + [
                'message_id' => $messageId,
                'at' => date('c', is_numeric($message['date'] ?? null) ? (int) $message['date'] : time()),
            ],
            // Bot steps: everything before registration when the bot answers; when only asked by an
            // admin, just the answers to its questions (the first message stays a real question).
            aside: $replies ? $user->step !== TelegramUser::STEP_DONE
                : $asking && (in_array($user->step, [TelegramUser::STEP_NAME, TelegramUser::STEP_PHONE], true)
                    || self::languageFromText(self::withoutEmoji((string) ($message['text'] ?? ''))) !== null
                    || str_starts_with((string) ($message['text'] ?? ''), '/')),
            awaitsReply: $replies,
        );
        $this->messageId = $messageId;

        // Record-only mode (TELEGRAM_BOT_REPLIES=false): no reply, registration or AI call —
        // unless an admin asked for this customer's registration info.
        if (! $replies && ! $asking) {
            return;
        }

        if (! RateLimiter::attempt("telegram-chat:{$chatId}", 15, fn () => true, 60)) {
            $this->api->sendMessage($chatId, __('chat.tg_too_fast', [], $locale));

            return;
        }

        $text = self::withoutEmoji((string) ($message['text'] ?? ''));

        if (str_starts_with($text, '/language')) {
            $user->update(['step' => TelegramUser::STEP_LANGUAGE]);
            $this->askLanguage($chatId);

            return;
        }

        if (str_starts_with($text, '/start') && ! $user->isRegistered()) {
            $this->askCurrentStep($user);

            return;
        }

        match ($user->step) {
            // Names are validated as typed, emoji included.
            TelegramUser::STEP_NAME => $this->receiveName($user, trim((string) ($message['text'] ?? ''))),
            TelegramUser::STEP_PHONE => $this->receivePhone($user, $message),
            TelegramUser::STEP_DONE => $replies ? $this->chat($user, $message, $text) : null,
            default => $this->receiveLanguage($user, $text),
        };
    }

    /** A person's name as typed: 2–60 letters, spaces, apostrophes (Uzbek ʻ ʼ), dots and hyphens. */
    public static function isValidName(string $name): bool
    {
        return mb_strlen($name) >= 2 && mb_strlen($name) <= 60
            && preg_match("/^[\p{L}\p{M}][\p{L}\p{M} '’‘ʻʼ`.-]*$/u", $name) === 1;
    }

    /** Business and group updates are only recorded. Returns true when the update was one of them. */
    private function record(array $update): bool
    {
        // Malformed parts (not an object) are ignored.
        foreach (['business_connection', 'business_message', 'edited_business_message', 'deleted_business_messages'] as $key) {
            if (array_key_exists($key, $update)) {
                if (is_array($part = $update[$key])) {
                    match ($key) {
                        'business_connection' => $this->recorder->connection($part),
                        'business_message' => $this->recorder->businessMessage($part),
                        'edited_business_message' => $this->recorder->businessMessage($part, edited: true),
                        'deleted_business_messages' => $this->recorder->businessDeleted($part),
                    };
                }

                return true;
            }
        }

        foreach (['message' => false, 'edited_message' => true] as $key => $edited) {
            $part = $update[$key] ?? null;
            if (is_array($part) && is_array($part['chat'] ?? null) && in_array($part['chat']['type'] ?? null, ['group', 'supergroup'], true)) {
                $this->recorder->groupMessage($part, $edited);

                return true;
            }
        }

        return false;
    }

    /**
     * Text without the emoji our buttons add (flags, 💡, 📞…), so a tapped button
     * matches its label and the AI gets the plain question.
     */
    public static function withoutEmoji(string $text): string
    {
        $emoji = '[\p{So}\p{Sk}\x{1F1E6}-\x{1F1FF}\x{FE0F}\x{200D}\x{20E3}]';

        return trim(preg_replace("/^(?:$emoji|\s)+|(?:$emoji|\s)+$/u", '', $text) ?? $text);
    }

    // Registration -------------------------------------------------------------

    /** Repeat the prompt for the step the customer is on (e.g. after /start). */
    private function askCurrentStep(TelegramUser $user): void
    {
        match ($user->step) {
            TelegramUser::STEP_NAME => $this->api->sendMessage($user->telegram_id, __('chat.tg_ask_name', [], $user->locale), [
                'reply_markup' => ['remove_keyboard' => true],
            ]),
            TelegramUser::STEP_PHONE => $this->askPhone($user, __('chat.tg_ask_phone', ['name' => $user->name], $user->locale)),
            default => $this->askLanguage($user->telegram_id),
        };
    }

    private function askLanguage(int|string $chatId): void
    {
        $buttons = array_map(fn (string $label) => ['text' => $label], array_values(self::LANGUAGES));

        $this->api->sendMessage($chatId, self::LANGUAGE_PROMPT, [
            'reply_markup' => ['keyboard' => [$buttons], 'resize_keyboard' => true, 'one_time_keyboard' => true],
        ]);
    }

    /** The language a tapped picker button (or "uz" / "ru" / "en") stands for. */
    private static function languageFromText(string $text): ?string
    {
        return array_search($text, array_map(self::withoutEmoji(...), self::LANGUAGES), true)
            ?: (array_key_exists(mb_strtolower($text), self::LANGUAGES) ? mb_strtolower($text) : null);
    }

    private function receiveLanguage(TelegramUser $user, string $text): void
    {
        $locale = self::languageFromText($text);

        if (! $locale) {
            $this->askLanguage($user->telegram_id);

            return;
        }

        $user->locale = $locale;

        if ($user->isRegistered()) {
            $user->update(['step' => TelegramUser::STEP_DONE]);
            $this->api->sendMessage($user->telegram_id, __('chat.tg_language_changed', [], $locale), [
                'reply_markup' => $this->keyboard((array) __('chat.starters', [], $locale), false, $locale),
            ]);

            return;
        }

        // A name typed before switching language is kept.
        $user->update(['step' => $user->name ? TelegramUser::STEP_PHONE : TelegramUser::STEP_NAME]);
        $this->askCurrentStep($user);
    }

    private function receiveName(TelegramUser $user, string $text): void
    {
        $name = preg_replace('/\s+/u', ' ', $text);

        if (! self::isValidName($name)) {
            $this->api->sendMessage($user->telegram_id, __('chat.tg_name_invalid', [], $user->locale));

            return;
        }

        $user->update(['name' => $name, 'step' => TelegramUser::STEP_PHONE]);
        $this->askPhone($user, __('chat.tg_ask_phone', ['name' => $name], $user->locale));
    }

    private function askPhone(TelegramUser $user, string $text): void
    {
        $this->api->sendMessage($user->telegram_id, $text, [
            'reply_markup' => [
                'keyboard' => [[['text' => __('chat.tg_share_phone', [], $user->locale), 'request_contact' => true]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ]);
    }

    /** Only the customer's own contact counts: the number linked to their Telegram account. */
    private function receivePhone(TelegramUser $user, array $message): void
    {
        $contact = $message['contact'] ?? null;
        $ownContact = $contact
            && isset($contact['user_id'], $message['from']['id'])
            && (string) $contact['user_id'] === (string) $message['from']['id'];

        if (! $ownContact) {
            $this->askPhone($user, __('chat.tg_phone_not_own', [], $user->locale));

            return;
        }

        $user->update([
            'phone' => '+'.ltrim((string) $contact['phone_number'], '+'),
            'step' => TelegramUser::STEP_DONE,
            'registered_at' => now(),
            'ask_registration' => false,
        ]);

        // Without AI replies, no suggested-question buttons (nobody would answer them).
        $this->api->sendMessage($user->telegram_id, __('chat.tg_registered', [], $user->locale), [
            'reply_markup' => config('services.telegram.replies')
                ? $this->keyboard((array) __('chat.starters', [], $user->locale), false, $user->locale)
                : ['remove_keyboard' => true],
        ]);
    }

    // Chat ---------------------------------------------------------------------

    private function chat(TelegramUser $user, array $message, string $text): void
    {
        $locale = $user->locale;

        if (! array_key_exists('text', $message)) {
            return; // already recorded by type and file ID; not answered
        }

        if ($text === '') {
            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->api->sendMessage($user->telegram_id, __('chat.tg_welcome', [], $locale), [
                'reply_markup' => $this->keyboard((array) __('chat.starters', [], $locale), false, $locale),
            ]);

            return;
        }

        if ($text === self::withoutEmoji(__('chat.tg_request_call', [], $locale))) {
            $this->requestCall($user);

            return;
        }

        $this->answer($user, mb_substr($text, 0, 1000));
    }

    private function answer(TelegramUser $user, string $text): void
    {
        $locale = $user->locale;
        $this->api->call('sendChatAction', ['chat_id' => $user->telegram_id, 'action' => 'typing']);

        $conversation = $this->conversation($user);

        // Replies BDB staff sent from the admin panel count as the assistant's side.
        $history = collect($conversation->textMessages())
            ->map(fn (array $m) => ['role' => $m['role'] === 'user' ? 'user' : 'assistant', 'content' => $m['content']])
            ->slice(-11)
            ->push(['role' => 'user', 'content' => $text])
            ->values()
            ->all();
        while ($history && $history[0]['role'] !== 'user') {
            array_shift($history);
        }

        // 1–3 words ("salom", "ok", "?"): a light creative reply (joke, fun fact or our latest article).
        // Longer messages: an answer from the website data. Both fall back (and alert admins) when the AI fails.
        $short = ChatAssistant::isShort($text);
        $result = $short
            ? $this->assistant->creativeReply($text, $locale)
            : $this->assistant->reply($history, $locale, 'Telegram bot', brief: true);

        $conversation->appendReply($this->messageId, $text, $result['reply'], $result['answered']);
        if (! $short) {
            ClientRequest::record($conversation, $text, $result); // small talk is not a client request
        }

        $reply = $result['reply'];
        if ($result['offer_callback']) {
            $reply .= "\n\n".__('chat.tg_call_hint', [], $locale);
        }

        $this->api->sendMessage($user->telegram_id, $reply, [
            'reply_markup' => $this->keyboard($result['suggestions'], $result['offer_callback'], $locale),
        ]);
    }

    /** The customer edited a message in the bot chat: update the recorded copy (or record the new text). */
    private function recordEdit(array $message): void
    {
        $chatId = (string) $message['chat']['id'];
        $messageId = is_numeric($message['message_id'] ?? null) ? (int) $message['message_id'] : null;
        $content = TelegramRecorder::describe($message);

        $conversation = ChatConversation::where('channel', 'telegram')->where('external_id', $chatId)
            ->latest('updated_at')->latest('id')->first();
        if (! $conversation || $messageId === null || ! $conversation->applyEdit($messageId, $content)) {
            // A late edit goes into the latest session however old (like Business chats), without
            // reopening it: a session holding only an edit must not hide a chat that is waiting.
            $user = TelegramUser::firstWhere('telegram_id', $chatId);
            $target = $conversation ?? ($user ? $this->conversation($user) : null);
            if ($target) {
                $target->timestamps = ! $target->exists;
                $target->appendIncoming($content + ['message_id' => $messageId, 'at' => date('c'), 'edited' => true, 'late_edit' => true], awaitsReply: (bool) config('services.telegram.replies'));
                $target->timestamps = true;
            }
        }
    }

    /** The registered customer asked for a call: a lead with their registration details. */
    private function requestCall(TelegramUser $user): void
    {
        $conversation = $this->conversation($user);
        $messages = collect($conversation->textMessages());

        // Repeated taps within an hour don't create duplicate leads and sales alerts.
        $recent = $conversation->lead()->where('created_at', '>=', now()->subHour())->exists();
        if ($recent) {
            $this->api->sendMessage($user->telegram_id, __('chat.tg_thanks', [], $user->locale), [
                'reply_markup' => ['remove_keyboard' => true],
            ]);

            return;
        }

        $lead = ChatLead::create([
            'channel' => 'telegram',
            'name' => $user->name,
            'phone' => $user->phone,
            'topic' => $messages->where('role', 'user')->last()['content'] ?? null,
            'locale' => $user->locale,
            'page_url' => $user->username ? 'https://t.me/'.$user->username : null,
            'transcript' => $messages->map(fn (array $m) => ['role' => $m['role'], 'content' => $m['content']])->slice(-20)->values()->all(),
        ]);

        $conversation->fill(['chat_lead_id' => $lead->id])->save();
        $this->notifier->newLead($lead);

        $this->api->sendMessage($user->telegram_id, __('chat.tg_thanks', [], $user->locale), [
            'reply_markup' => ['remove_keyboard' => true],
        ]);
    }

    private function conversation(TelegramUser $user): ChatConversation
    {
        $conversation = ChatConversation::where('channel', 'telegram')
            ->where('external_id', $user->telegram_id)
            ->where('updated_at', '>=', now()->subHours(self::SESSION_HOURS))
            ->latest('updated_at')
            ->first()
            ?? new ChatConversation([
                'uuid' => (string) Str::uuid(),
                'channel' => 'telegram',
                'external_id' => $user->telegram_id,
                'page' => 'Telegram bot',
                'messages' => [],
            ]);

        // Follows /language changes.
        $conversation->locale = $user->locale ?? 'ru'; // before a language is picked: Russian

        return $conversation;
    }

    /** Suggested questions as tap buttons, plus a "request a call" button when a callback is offered. */
    private function keyboard(array $suggestions, bool $offerCallback, string $locale): array
    {
        $rows = [];
        if ($offerCallback) {
            $rows[] = [['text' => __('chat.tg_request_call', [], $locale)]];
        }
        foreach (array_slice($suggestions, 0, 3) as $suggestion) {
            $rows[] = [['text' => '💡 '.mb_substr((string) $suggestion, 0, 60)]];
        }

        return $rows
            ? ['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => true]
            : ['remove_keyboard' => true];
    }
}
