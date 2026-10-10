<?php

namespace App\Services\Telegram;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\TelegramUser;
use App\Services\ChatAssistant;
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
 * Only private chats are handled, so the sales group that receives lead alerts is ignored.
 */
class TelegramBot
{
    /** A chat idle longer than this starts a new conversation. */
    private const SESSION_HOURS = 24;

    /** Language picker buttons; the picker prompt is shown in all three languages. */
    public const LANGUAGES = ['uz' => 'Oʻzbekcha', 'ru' => 'Русский', 'en' => 'English'];

    public const LANGUAGE_PROMPT = 'Tilni tanlang · Выберите язык · Choose a language';

    /** Message keys that carry a downloadable file, checked in this order (an animation also has "document"). */
    private const FILE_TYPES = ['animation', 'photo', 'video', 'video_note', 'voice', 'audio', 'sticker', 'document'];

    /** Other non-text message kinds, stored by type only. */
    private const OTHER_TYPES = ['contact', 'location', 'venue', 'poll', 'dice', 'story'];

    public function __construct(
        private TelegramApi $api,
        private ChatAssistant $assistant,
        private TelegramNotifier $notifier,
    ) {}

    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! $message || ($message['chat']['type'] ?? null) !== 'private') {
            return;
        }

        $chatId = $message['chat']['id'];
        $from = $message['from'] ?? [];

        $user = TelegramUser::firstOrNew(['telegram_id' => (string) $chatId]);
        $user->username = $from['username'] ?? null;
        $user->save();

        $locale = $user->locale ?? 'ru';

        if (! RateLimiter::attempt("telegram-chat:{$chatId}", 15, fn () => true, 60)) {
            $this->api->sendMessage($chatId, __('chat.tg_too_fast', [], $locale));

            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if (str_starts_with($text, '/language')) {
            $user->update(['step' => TelegramUser::STEP_LANGUAGE]);
            $this->askLanguage($chatId);

            return;
        }

        match ($user->step) {
            TelegramUser::STEP_NAME => $this->receiveName($user, $text),
            TelegramUser::STEP_PHONE => $this->receivePhone($user, $message),
            TelegramUser::STEP_DONE => $this->chat($user, $message, $text),
            default => $this->receiveLanguage($user, $text),
        };
    }

    // Registration -------------------------------------------------------------

    private function askLanguage(int|string $chatId): void
    {
        $buttons = array_map(fn (string $label) => ['text' => $label], array_values(self::LANGUAGES));

        $this->api->sendMessage($chatId, self::LANGUAGE_PROMPT, [
            'reply_markup' => ['keyboard' => [$buttons], 'resize_keyboard' => true, 'one_time_keyboard' => true],
        ]);
    }

    private function receiveLanguage(TelegramUser $user, string $text): void
    {
        $locale = array_search($text, self::LANGUAGES, true)
            ?: (array_key_exists(mb_strtolower($text), self::LANGUAGES) ? mb_strtolower($text) : null);

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

        $user->update(['step' => TelegramUser::STEP_NAME]);
        $this->api->sendMessage($user->telegram_id, __('chat.tg_ask_name', [], $locale), [
            'reply_markup' => ['remove_keyboard' => true],
        ]);
    }

    private function receiveName(TelegramUser $user, string $text): void
    {
        $name = preg_replace('/\s+/u', ' ', $text);
        $valid = mb_strlen($name) >= 2 && mb_strlen($name) <= 60
            && preg_match("/^[\p{L}\p{M}][\p{L}\p{M} '’ʻʼ.-]*$/u", $name);

        if (! $valid) {
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
        ]);

        $this->api->sendMessage($user->telegram_id, __('chat.tg_registered', [], $user->locale), [
            'reply_markup' => $this->keyboard((array) __('chat.starters', [], $user->locale), false, $user->locale),
        ]);
    }

    // Chat ---------------------------------------------------------------------

    private function chat(TelegramUser $user, array $message, string $text): void
    {
        $locale = $user->locale;

        if (! isset($message['text'])) {
            $this->recordMedia($user, $message);

            return;
        }

        if ($text === '') {
            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->api->sendMessage($user->telegram_id, __('chat.welcome', [], $locale), [
                'reply_markup' => $this->keyboard((array) __('chat.starters', [], $locale), false, $locale),
            ]);

            return;
        }

        if ($text === __('chat.tg_request_call', [], $locale)) {
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

        $result = config('services.gemini.key')
            ? $this->assistant->reply($history, $locale, 'Telegram bot', brief: true)
            : $this->assistant->fallbackReply($locale);

        $conversation->appendExchange($text, $result['reply'], $result['answered']);

        $reply = $result['reply'];
        if ($result['offer_callback']) {
            $reply .= "\n\n".__('chat.tg_call_hint', [], $locale);
        }

        $this->api->sendMessage($user->telegram_id, $reply, [
            'reply_markup' => $this->keyboard($result['suggestions'], $result['offer_callback'], $locale),
        ]);
    }

    /** Store a non-text message by type and file ID only; it is not answered. */
    private function recordMedia(TelegramUser $user, array $message): void
    {
        foreach (self::FILE_TYPES as $type) {
            if (isset($message[$type])) {
                // A photo comes in several sizes; keep the largest (last).
                $file = $type === 'photo' ? end($message['photo']) : $message[$type];
                $this->conversation($user)->appendMedia($type, $file['file_id'] ?? null);

                return;
            }
        }

        $type = collect(self::OTHER_TYPES)->first(fn (string $t) => isset($message[$t])) ?? 'other';
        $this->conversation($user)->appendMedia($type, null);
    }

    /** The registered customer asked for a call: a lead with their registration details. */
    private function requestCall(TelegramUser $user): void
    {
        $conversation = $this->conversation($user);
        $messages = collect($conversation->textMessages());

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

        $this->api->sendMessage($user->telegram_id, __('chat.thanks', [], $user->locale), [
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
        $conversation->locale = $user->locale;

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
            $rows[] = [['text' => mb_substr((string) $suggestion, 0, 60)]];
        }

        return $rows
            ? ['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => true]
            : ['remove_keyboard' => true];
    }
}
