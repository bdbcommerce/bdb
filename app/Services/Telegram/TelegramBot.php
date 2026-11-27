<?php

namespace App\Services\Telegram;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ClientRequest;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use App\Services\Ai\VoiceTranscriber;
use App\Services\AiErrorAlerts;
use App\Services\ChatAssistant;
use App\Services\Invoices\InvoiceParser;
use App\Services\SalesAssistant\ExpertDesk;
use App\Services\SalesAssistant\LeadDesk;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The website assistant as a Telegram bot. Handles one webhook update.
 *
 * A new customer first registers: picks a language, types a name and shares the
 * phone number linked to their Telegram account. Only then are text questions
 * answered by ChatAssistant (briefly). Voice messages up to a minute are transcribed (Groq Whisper,
 * VoiceTranscriber), the transcript is sent back and answered like text. Other messages (photos,
 * files…) are not answered; only their type and Telegram file ID are stored in the conversation.
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
        private VoiceTranscriber $transcriber,
    ) {}

    /** Cache key prefix: a guest tapped "Request a call", so the lead is created once registration is done. */
    private const CALLBACK_AFTER_REGISTRATION = 'telegram-callback-after-registration:';

    /** Cache key prefix: /language was used during registration, which resumes after the language pick. */
    private const REGISTERING = 'telegram-registering:';

    /** Guests are invited to register on their first answer and then every this many answers. */
    private const INVITE_EVERY = 4;

    /** Voice messages forwarded to the sales chat per customer per hour (more are only recorded). */
    private const VOICE_FORWARDS_PER_HOUR = 20;

    /** AI answers a day for one unregistered customer; then they are asked to register. */
    public const GUEST_DAILY_ANSWERS = 30;

    /** Voice messages up to this long (seconds) are transcribed and answered. */
    public const VOICE_MAX_SECONDS = 60;

    /** Transcriptions per customer per hour. */
    private const VOICE_PER_HOUR = 10;

    /** Larger files are not sent for transcription (a minute of Telegram voice is about 0.3 MB, of a 192 kbit/s MP3 1.4 MB). */
    private const VOICE_MAX_BYTES = 1536 * 1024;

    /** Telegram ID of the private message being handled (to mark it answered). */
    private ?int $messageId = null;

    public function handle(array $update): void
    {
        // Every chat the bot sees goes to the chat / group pickers (TelegramChat), before anything else.
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            is_array($update[$key] ?? null) && rescue(fn () => TelegramChat::see($update[$key]['chat'] ?? null), report: false); // never stops the update
        }
        // The bot was added to / removed from a group (or a person blocked it): only the list changes.
        if (is_array($member = $update['my_chat_member'] ?? null)) {
            rescue(fn () => TelegramChat::see($member['chat'] ?? null, is_string($member['new_chat_member']['status'] ?? null) ? $member['new_chat_member']['status'] : 'member'), report: false);

            return;
        }
        // The invoice source chat belongs to the invoice parser (Admin → Invoice parser): photos are read
        // by Claude, everything else there (text, edits) is ignored and never recorded or answered.
        foreach (['message', 'edited_message'] as $key) {
            if (is_array($part = $update[$key] ?? null) && ($parser = app(InvoiceParser::class))->handles($part)) {
                if ($key === 'message') {
                    // Stored here; `invoices:process` (every minute, one at a time) reads it, so a flood of photos
                    // never ties up the web workers. No scheduler run lately (no cron on the server): read it here,
                    // after the response, one worker at a time.
                    if ($parser->capture($part) && ! InvoiceParser::schedulerRunning()) {
                        $parser->drain(10);
                    }
                }

                return;
            }
        }

        // Buttons under the AI's questions to experts (ExpertDesk); other buttons are not used by the bot.
        if (is_array($update['callback_query'] ?? null)) {
            // "I take it" under a request in the sales chat, else an expert's buttons.
            app(LeadDesk::class)->callback($update['callback_query']) || app(ExpertDesk::class)->callback($update['callback_query']);

            return;
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
        // The bot may write to them (experts and the main admin need this: /start once).
        $user->bot_chat_at || $user->forceFill(['bot_chat_at' => now()])->save();

        // An employee who answers the AI's questions: their answer, transfer note or /start (not recorded as a chat).
        if ($user->isExpert() && app(ExpertDesk::class)->message($user, $message)) {
            return;
        }

        $locale = $user->locale ?? 'ru';
        $replies = (bool) config('services.telegram.replies');
        // Record-only mode, but an admin ticked "ask for registration info" for this customer.
        $asking = ! $replies && $user->ask_registration && ! $user->isRegistered();

        // Every message is recorded: text as text, anything else by type and Telegram file ID.
        $messageId = is_numeric($message['message_id'] ?? null) ? (int) $message['message_id'] : null;

        // The sales assistant (Admin → Sales assistant) talks with every customer except employees: it replaces
        // registration and the Q&A answers. Voice still goes to the sales chat; the assistant is told about it.
        $this->messageId = $messageId;
        if (SalesAssistantSetting::current()->enabledFor('telegram', $user, (string) $chatId, is_string($from['username'] ?? null) ? $from['username'] : null)) {
            $conversation = $this->conversation($user);
            $conversation->appendIncoming(TelegramRecorder::describe($message) + TelegramRecorder::replyTo($message) + [
                'message_id' => $messageId,
                'at' => date('c', is_numeric($message['date'] ?? null) ? (int) $message['date'] : time()),
            ], awaitsReply: false);
            if (is_array($message['voice'] ?? null) || is_array($message['audio'] ?? null)) {
                $this->forwardVoice($user, $message);
            }
            if (RateLimiter::attempt("telegram-chat:{$chatId}", 15, fn () => true, 60)) {
                app(SalesAssistant::class)->botMessage($user, $conversation->refresh(), $message);
            }

            return;
        }
        $this->conversation($user)->appendIncoming(
            TelegramRecorder::describe($message) + [
                'message_id' => $messageId,
                'at' => date('c', is_numeric($message['date'] ?? null) ? (int) $message['date'] : time()),
            ],
            // Bot steps: everything before registration when the bot answers; when only asked by an
            // admin, just the answers to its questions (the first message stays a real question).
            aside: $replies ? ! in_array($user->step, [TelegramUser::STEP_DONE, TelegramUser::STEP_GUEST], true)
                : $asking && (in_array($user->step, [TelegramUser::STEP_NAME, TelegramUser::STEP_PHONE], true)
                    || self::languageFromText(self::withoutEmoji((string) ($message['text'] ?? ''))) !== null
                    || str_starts_with((string) ($message['text'] ?? ''), '/')),
            awaitsReply: $replies,
        );
        $this->messageId = $messageId;

        // Voice messages and audio files go to staff: forwarded to the sales chat, the bot stays silent
        // (any mode, any step). TELEGRAM_VOICE_MODE=transcribe brings back Groq transcription + AI answer.
        if ((is_array($message['voice'] ?? null) || is_array($message['audio'] ?? null)) && config('services.telegram.voice') !== 'transcribe') {
            $this->forwardVoice($user, $message);

            return;
        }

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
            // Switching language in the middle of registration: it goes on in the new language.
            if (in_array($user->step, [TelegramUser::STEP_NAME, TelegramUser::STEP_PHONE], true)) {
                Cache::put(self::REGISTERING.$user->telegram_id, true, now()->addHour());
            }
            $user->update(['step' => TelegramUser::STEP_LANGUAGE]);
            $this->askLanguage($chatId);

            return;
        }

        if (str_starts_with($text, '/start') && (! $user->isRegistered() || $user->step === TelegramUser::STEP_PHONE)) {
            $user->isRegistered()
                ? $this->askPhone($user, __('chat.tg_call_confirm_phone', [], $locale))
                : $this->askCurrentStep($user);

            return;
        }

        // Registration is optional when the bot answers: "Not now" goes back to asking questions.
        if ($replies && in_array($user->step, [TelegramUser::STEP_NAME, TelegramUser::STEP_PHONE], true)
            && $text === self::withoutEmoji(__('chat.tg_not_now', [], $locale))) {
            Cache::forget(self::CALLBACK_AFTER_REGISTRATION.$user->telegram_id);
            // The customer declined: an admin's "ask for registration" request is not repeated on every message.
            $registered = $user->isRegistered(); // a registered customer was confirming the phone for a call
            $user->update(['step' => $registered ? TelegramUser::STEP_DONE : TelegramUser::STEP_GUEST, 'ask_registration' => false]);
            $this->api->sendMessage($chatId, __($registered ? 'chat.tg_call_later' : 'chat.tg_registration_later', [], $locale), [
                'reply_markup' => $this->keyboard((array) __('chat.starters', [], $locale), $registered, $locale, register: ! $registered),
            ]);

            return;
        }

        // Name/phone asked in the Premium-account chat are answered there, not here: in the bot chat this
        // customer is a guest meanwhile (or, in record-only mode, only recorded).
        $step = $user->step;
        if (in_array($step, [TelegramUser::STEP_NAME, TelegramUser::STEP_PHONE], true) && Cache::has(TelegramRecorder::BUSINESS_REGISTERING.$user->telegram_id)) {
            $step = $replies ? TelegramUser::STEP_GUEST : null;
        }

        match ($step) {
            null => null,
            // Names are validated as typed, emoji included.
            TelegramUser::STEP_NAME => $this->receiveName($user, trim((string) ($message['text'] ?? '')), $message, $text),
            TelegramUser::STEP_PHONE => $this->receivePhone($user, $message),
            TelegramUser::STEP_DONE => $replies ? $this->chat($user, $message, $text) : null,
            // Not registered but chatting (replies on): answered briefly, invited to register.
            TelegramUser::STEP_GUEST => $replies ? $this->chat($user, $message, $text, guest: true) : $this->receiveLanguage($user, $text),
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
            TelegramUser::STEP_NAME => $this->askName($user, __('chat.tg_ask_name', [], $user->locale)),
            TelegramUser::STEP_PHONE => $this->askPhone($user, __('chat.tg_ask_phone', ['name' => $user->name], $user->locale)),
            TelegramUser::STEP_GUEST => $this->api->sendMessage($user->telegram_id, __('chat.tg_guest_welcome', [], $user->locale), [
                'reply_markup' => $this->keyboard((array) __('chat.starters', [], $user->locale), false, $user->locale, register: true),
            ]),
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
            Cache::forget(self::CALLBACK_AFTER_REGISTRATION.$user->telegram_id);
            Cache::forget(self::REGISTERING.$user->telegram_id);
            $user->update(['step' => TelegramUser::STEP_DONE]);
            $this->api->sendMessage($user->telegram_id, __('chat.tg_language_changed', [], $locale), [
                'reply_markup' => $this->keyboard((array) __('chat.starters', [], $locale), false, $locale),
            ]);

            return;
        }

        // When the bot answers, registration is optional: questions first, an invitation to register with
        // the answers; /language used during registration resumes it (REGISTERING), at the phone step if the name is known.
        // In record-only mode an admin asked for the details, so the questions start right away.
        $registering = Cache::pull(self::REGISTERING.$user->telegram_id);
        // A stored name alone (typed before "Not now", or by an admin) does not restart registration.
        $step = match (true) {
            ! $registering && (bool) config('services.telegram.replies') => TelegramUser::STEP_GUEST,
            (bool) $user->name => TelegramUser::STEP_PHONE,
            default => TelegramUser::STEP_NAME,
        };
        $user->update(['step' => $step]);
        $this->askCurrentStep($user);
    }

    /** Start registration (the guest tapped "Register" or "Request a call"); a known name is not asked again. */
    private function startRegistration(TelegramUser $user, string $intro = ''): void
    {
        Cache::forget(TelegramRecorder::BUSINESS_REGISTERING.$user->telegram_id);
        $intro = $intro !== '' ? $intro."\n\n" : '';

        if ($user->name) {
            $user->update(['step' => TelegramUser::STEP_PHONE]);
            $this->askPhone($user, $intro.__('chat.tg_ask_phone', ['name' => $user->name], $user->locale));

            return;
        }

        $user->update(['step' => TelegramUser::STEP_NAME]);
        $this->askName($user, $intro.__('chat.tg_ask_name', [], $user->locale));
    }

    /** With bot replies on, registration can be left for later ("Not now"). */
    private function laterButton(TelegramUser $user): array
    {
        return config('services.telegram.replies') ? [[['text' => __('chat.tg_not_now', [], $user->locale)]]] : [];
    }

    private function askName(TelegramUser $user, string $text): void
    {
        $rows = $this->laterButton($user);
        $this->api->sendMessage($user->telegram_id, $text, [
            'reply_markup' => $rows ? ['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => true] : ['remove_keyboard' => true],
        ]);
    }

    private function receiveName(TelegramUser $user, string $text, array $message = [], string $plain = ''): void
    {
        $name = preg_replace('/\s+/u', ' ', $text);

        // A name has at most four words: a longer text (or one with "?") is a question typed at the wrong
        // moment. When the bot answers, registration is left for later and the question is answered.
        if (config('services.telegram.replies') && (str_contains($name, '?') || count(explode(' ', $name)) > 4)) {
            Cache::forget(self::CALLBACK_AFTER_REGISTRATION.$user->telegram_id);
            $user->update(['step' => TelegramUser::STEP_GUEST]);
            $this->chat($user, $message, $plain);

            return;
        }
        if (! self::isValidName($name) || count(explode(' ', $name)) > 4) {
            $this->askName($user, __('chat.tg_name_invalid', [], $user->locale));

            return;
        }

        $user->update(['name' => $name, 'step' => TelegramUser::STEP_PHONE]);
        $this->askPhone($user, __('chat.tg_ask_phone', ['name' => $name], $user->locale));
    }

    private function askPhone(TelegramUser $user, string $text): void
    {
        $this->api->sendMessage($user->telegram_id, $text, [
            'reply_markup' => [
                'keyboard' => [[['text' => __('chat.tg_share_phone', [], $user->locale), 'request_contact' => true]], ...$this->laterButton($user)],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ]);
    }

    /** Only the customer's own contact counts: the number linked to their Telegram account. */
    private function receivePhone(TelegramUser $user, array $message): void
    {
        $contact = $message['contact'] ?? null;
        $ownContact = is_array($contact)
            && isset($contact['user_id'], $message['from']['id'])
            && is_scalar($contact['phone_number'] ?? null)
            && (string) $contact['user_id'] === (string) $message['from']['id'];

        $typed = trim(self::withoutEmoji((string) ($message['text'] ?? '')));
        if (! $ownContact && config('services.telegram.replies') && (str_contains($typed, '?') || count(preg_split('/\s+/u', $typed)) > 4)) {
            // A question, not a phone: answered; the call request waits until the customer taps it again.
            Cache::forget(self::CALLBACK_AFTER_REGISTRATION.$user->telegram_id);
            $user->update(['step' => $user->isRegistered() ? TelegramUser::STEP_DONE : TelegramUser::STEP_GUEST]);
            $this->chat($user, $message, $typed, guest: ! $user->isRegistered());

            return;
        }

        if (! $ownContact) {
            $this->askPhone($user, __('chat.tg_phone_not_own', [], $user->locale));

            return;
        }

        $wasRegistered = $user->isRegistered();
        $user->update([
            'phone' => '+'.ltrim((string) $contact['phone_number'], '+'),
            'phone_verified_at' => now(),
            'step' => TelegramUser::STEP_DONE,
            'registered_at' => $user->registered_at ?? now(),
            'ask_registration' => false,
        ]);

        // A guest who tapped "Request a call" registered for it: the lead is created now.
        if (config('services.telegram.replies') && Cache::pull(self::CALLBACK_AFTER_REGISTRATION.$user->telegram_id)) {
            if (! $wasRegistered) {
                $this->api->sendMessage($user->telegram_id, __('chat.tg_registered', [], $user->locale));
            }
            $this->requestCall($user);

            return;
        }

        // Without AI replies, no suggested-question buttons (nobody would answer them).
        $this->api->sendMessage($user->telegram_id, __('chat.tg_registered', [], $user->locale), [
            'reply_markup' => config('services.telegram.replies')
                ? $this->keyboard((array) __('chat.starters', [], $user->locale), false, $user->locale)
                : ['remove_keyboard' => true],
        ]);
    }

    // Chat ---------------------------------------------------------------------

    private function chat(TelegramUser $user, array $message, string $text, bool $guest = false): void
    {
        $locale = $user->locale;

        if (! array_key_exists('text', $message)) {
            if (is_array($message['voice'] ?? null) || is_array($message['audio'] ?? null)) {
                $this->voice($user, $message);
            }

            return; // other files: already recorded by type and file ID; not answered
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

        $registered = $user->isRegistered();

        if ($text === self::withoutEmoji(__('chat.tg_register', [], $locale)) || str_starts_with($text, '/register')) {
            $registered
                ? $this->api->sendMessage($user->telegram_id, __('chat.tg_already_registered', ['name' => $user->name], $locale))
                : $this->startRegistration($user);

            return;
        }

        if ($text === self::withoutEmoji(__('chat.tg_request_call', [], $locale))) {
            if ($registered) {
                $this->requestCall($user);
            } else {
                // A call needs a name and phone: register first, then the lead is created (receivePhone).
                Cache::put(self::CALLBACK_AFTER_REGISTRATION.$user->telegram_id, true, now()->addDay());
                $this->startRegistration($user, __('chat.tg_call_needs_registration', [], $locale));
            }

            return;
        }

        $this->answer($user, mb_substr($text, 0, 1000));

        // An admin ticked "ask for registration info" (Telegram → Users): after the answer, the bot asks.
        if ($guest && $user->ask_registration && ! $user->isRegistered()) {
            $this->startRegistration($user);
        }
    }

    private function guestMayAsk(TelegramUser $user): bool
    {
        if (! RateLimiter::attempt("telegram-guest-ai:{$user->telegram_id}", self::GUEST_DAILY_ANSWERS, fn () => true, 86400)) {
            return false;
        }

        $key = 'telegram-guest-ai:'.now()->toDateString();
        Cache::add($key, 0, now()->endOfDay());
        $limit = max(0, (int) config('services.telegram.guest_daily_limit'));
        $used = Cache::increment($key);
        if ($used === $limit + 1) {
            app(AiErrorAlerts::class)->report('Telegram guests: daily AI limit reached',
                "Unregistered customers used {$limit} AI answers today (TELEGRAM_GUEST_DAILY_LIMIT). Until midnight they are asked to register; registered customers are not affected.", 'Telegram bot');
        }

        return $used <= $limit;
    }

    /** Forward a customer's voice / audio to the sales chat (at most VOICE_FORWARDS_PER_HOUR per customer). */
    private function forwardVoice(TelegramUser $user, array $message): void
    {
        $audio = is_array($message['voice'] ?? null) ? $message['voice'] : $message['audio'];
        if ($this->messageId === null || ! RateLimiter::attempt("telegram-voice-forward:{$user->telegram_id}", self::VOICE_FORWARDS_PER_HOUR, fn () => true, 3600)) {
            return; // still recorded in the chat; staff see it in the admin panel
        }

        $this->notifier->voiceMessage($user, $this->messageId, is_numeric($audio['duration'] ?? null) ? max(0, (int) $audio['duration']) : null);
    }

    /**
     * A voice message (or audio file) up to a minute: transcribed by Groq Whisper, the transcript is sent
     * back as a reply to it, then answered like a typed message. Without a Groq key voice is not answered.
     */
    private function voice(TelegramUser $user, array $message): void
    {
        $locale = $user->locale;
        $audio = is_array($message['voice'] ?? null) ? $message['voice'] : $message['audio'];
        $fileId = is_string($audio['file_id'] ?? null) ? $audio['file_id'] : null;
        $duration = is_numeric($audio['duration'] ?? null) ? (int) $audio['duration'] : null;

        if (! $fileId || $this->messageId === null || ! $this->transcriber->available()) {
            return;
        }

        $reply = fn (string $text) => $this->api->sendMessage($user->telegram_id, $text, [
            'reply_parameters' => ['message_id' => $this->messageId, 'allow_sending_without_reply' => true],
        ]);

        $tooLong = fn () => $reply(__('chat.tg_voice_too_long', ['seconds' => self::VOICE_MAX_SECONDS], $locale));
        $filename = VoiceTranscriber::filename(is_string($audio['file_name'] ?? null) ? $audio['file_name'] : null, (string) ($audio['mime_type'] ?? ''));
        if ($duration === null || $duration < 1 || $filename === null) {
            $reply(__('chat.tg_voice_failed', [], $locale)); // no length or a format Whisper can't read

            return;
        }
        // A first check on what the sender's app claims; the real length is checked after the download.
        if ($duration > self::VOICE_MAX_SECONDS
            || (is_numeric($audio['file_size'] ?? null) && (int) $audio['file_size'] > self::VOICE_MAX_BYTES)) {
            $tooLong();

            return;
        }

        // Groq's free tier limits audio per hour/day: a per-customer cap, a daily message cap and a
        // daily budget of audio seconds (as measured by Groq, so a mislabelled file still counts in full).
        $day = now()->toDateString();
        $dayKey = "telegram-voice:day:{$day}";
        $secondsKey = "telegram-voice:seconds:{$day}";
        Cache::add($dayKey, 0, now()->endOfDay());
        Cache::add($secondsKey, 0, now()->endOfDay());
        if (! RateLimiter::attempt("telegram-voice:{$user->telegram_id}", self::VOICE_PER_HOUR, fn () => true, 3600)
            || (int) Cache::get($secondsKey, 0) >= max(0, (int) config('services.groq.voice_daily_seconds'))
            || Cache::increment($dayKey) > max(0, (int) config('services.groq.voice_daily_limit'))) {
            $reply(__('chat.tg_voice_limit', [], $locale));

            return;
        }

        $this->api->call('sendChatAction', ['chat_id' => $user->telegram_id, 'action' => 'typing']);
        $file = $this->api->downloadFile($fileId, self::VOICE_MAX_BYTES);
        if (! $file) {
            $this->transcriber->downloadFailed();
            $reply(__('chat.tg_voice_failed', [], $locale));

            return;
        }
        // Telegram voice is Ogg Opus: its real length is read from the file, not taken from the sender.
        $real = VoiceTranscriber::oggSeconds($file['body']);
        if ($real !== null && $real > self::VOICE_MAX_SECONDS + 1) {
            $tooLong();

            return;
        }

        $result = $this->transcriber->transcribe($file['body'], VoiceTranscriber::filename($file['name']) ?? $filename);
        $measured = $result['seconds'] ?? $real ?? $duration;
        if ($result !== null) {
            Cache::increment($secondsKey, (int) ceil(max(1, $measured)));
        }
        if ($result !== null && $measured > self::VOICE_MAX_SECONDS + 1) {
            $tooLong(); // a file whose header lied about its length (e.g. not Ogg): not answered

            return;
        }

        $text = $result['text'] ?? null;
        if ($text !== null && VoiceTranscriber::isHallucination($text)) {
            $text = null; // silence: Whisper made up a subtitle credit or "thank you"
        }
        if ($text === null) {
            $reply(__('chat.tg_voice_failed', [], $locale));

            return;
        }

        $this->conversation($user)->setTranscript($this->messageId, $text);
        // Plain text (no parse_mode): whatever was said is shown as is.
        $reply(mb_substr('🎙 '.$text, 0, 4000));

        $this->answer($user, mb_substr($text, 0, 1000));
    }

    private function answer(TelegramUser $user, string $text): void
    {
        $locale = $user->locale;
        $this->api->call('sendChatAction', ['chat_id' => $user->telegram_id, 'action' => 'typing']);

        $conversation = $this->conversation($user);

        // Replies BDB staff sent from the admin panel count as the assistant's side.
        $history = collect($conversation->textMessages('bot'))
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
        $registered = $user->isRegistered();

        // Unverified accounts can reach the paid AI: a daily cap per guest and for all guests together.
        if (! $registered && ! $this->guestMayAsk($user)) {
            $this->api->sendMessage($user->telegram_id, __('chat.tg_guest_limit', [], $locale), [
                'reply_markup' => $this->keyboard([], false, $locale, register: true),
            ]);

            return;
        }
        $result = $short
            ? $this->assistant->creativeReply($text, $locale)
            // Registered customers get a fuller answer by name; guests a brief one and an invitation to register.
            : ($registered
                ? $this->assistant->reply($history, $locale, 'Telegram bot', telegram: true, customerName: $user->name)
                : $this->assistant->reply($history, $locale, 'Telegram bot', brief: true));

        $conversation->appendReply($this->messageId, $text, $result['reply'], $result['answered']);
        if (! $short) {
            ClientRequest::record($conversation, $text, $result); // small talk is not a client request
        }

        $reply = $result['reply'];
        if ($result['offer_callback']) {
            $reply .= "\n\n".__($registered ? 'chat.tg_call_hint' : 'chat.tg_guest_call_hint', [], $locale);
        } elseif (! $registered) {
            // The first answer in a session, then every INVITE_EVERY answers: not on every message.
            $answers = collect($conversation->messages)->where('role', 'assistant')->count();
            if ($answers === 1 || $answers % self::INVITE_EVERY === 0) {
                $reply .= "\n\n".__('chat.tg_guest_invite', [], $locale);
            }
        }

        $this->api->sendMessage($user->telegram_id, mb_substr($reply, 0, 4000), [
            'reply_markup' => $this->keyboard($result['suggestions'], $result['offer_callback'], $locale, register: ! $registered),
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
        // Sales only call numbers shared with Telegram's contact button (a phone typed in a Premium-account
        // chat could be anyone's): confirm it first, then the lead is created in receivePhone.
        if (! $user->hasVerifiedPhone()) {
            Cache::put(self::CALLBACK_AFTER_REGISTRATION.$user->telegram_id, true, now()->addDay());
            Cache::forget(TelegramRecorder::BUSINESS_REGISTERING.$user->telegram_id);
            $user->update(['step' => TelegramUser::STEP_PHONE]);
            $this->askPhone($user, __('chat.tg_call_confirm_phone', [], $user->locale));

            return;
        }

        $conversation = $this->conversation($user);
        $messages = collect($conversation->textMessages('bot'));

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
    private function keyboard(array $suggestions, bool $offerCallback, string $locale, bool $register = false): array
    {
        $rows = [];
        if ($offerCallback) {
            $rows[] = [['text' => __('chat.tg_request_call', [], $locale)]];
        }
        if ($register) {
            $rows[] = [['text' => __('chat.tg_register', [], $locale)]];
        }
        foreach (array_slice($suggestions, 0, 3) as $suggestion) {
            $rows[] = [['text' => '💡 '.mb_substr((string) $suggestion, 0, 60)]];
        }

        return $rows
            ? ['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => true]
            : ['remove_keyboard' => true];
    }
}
