<?php

namespace App\Services\Telegram;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Records chats the bot only listens to — nothing here sends a reply:
 * - business: private chats of a Telegram Premium account that connected the bot
 *   (Telegram Business). Messages from the account owner get role "owner", the other
 *   side "user".
 * - group: groups the bot is a member of, every message as role "member" with the sender's name.
 *   The sales and alert chats (lead notifications, internal) are not recorded.
 * Edits update the stored text; deletions (business only, Telegram reports no group deletions)
 * are marked, the text is kept.
 */
class TelegramRecorder
{
    /** Message fields that are metadata, not the message itself. */
    private const META = [
        'message_id', 'message_thread_id', 'from', 'sender_chat', 'sender_boost_count', 'sender_business_bot', 'sender_tag',
        'date', 'edit_date', 'business_connection_id', 'chat', 'is_topic_message', 'is_automatic_forward', 'is_from_offline',
        'forward_origin', 'forward_from', 'forward_from_chat', 'forward_from_message_id', 'forward_signature', 'forward_sender_name', 'forward_date',
        'reply_to_message', 'external_reply', 'quote', 'reply_to_story', 'reply_to_checklist_task_id', 'via_bot',
        'has_protected_content', 'media_group_id', 'author_signature', 'paid_star_count', 'is_paid_post', 'suggested_post_info',
        'direct_messages_topic', 'text', 'entities', 'link_preview_options', 'effect_id', 'caption', 'caption_entities',
        'show_caption_above_media', 'has_media_spoiler', 'reply_markup',
    ];

    /** A chat idle longer than this, or this long, starts a new conversation. */
    private const SESSION_HOURS = 24;

    private const MAX_MESSAGES = 500;

    public function __construct(private TelegramApi $api) {}

    /** The Premium account connected, changed or disconnected the bot. */
    public function connection(array $connection): void
    {
        TelegramBusinessConnection::fromTelegram($connection);
    }

    public function businessMessage(array $message, bool $edited = false): void
    {
        $message = $this->clean($message);
        $connection = $this->findConnection((string) ($message['business_connection_id'] ?? ''));
        if (! $connection || ! isset($message['chat']['id'])) {
            return;
        }

        $fromOwner = $connection->user_id !== '' && (string) ($message['from']['id'] ?? '') === $connection->user_id;
        $conversation = $this->conversation('business', $message['chat'], $connection, $message['from'] ?? [], $edited);

        $this->store($conversation, $message, $fromOwner ? 'owner' : 'user', $edited);
    }

    public function businessDeleted(array $deleted): void
    {
        $ids = array_map('intval', array_filter((array) ($deleted['message_ids'] ?? []), 'is_numeric'));
        $chatId = $deleted['chat']['id'] ?? null;
        $connectionId = $deleted['business_connection_id'] ?? null;
        if (! $ids || ! is_scalar($chatId) || ! is_scalar($connectionId) || (string) $connectionId === '') {
            return;
        }

        // The deleted messages may be in an earlier session of this chat.
        $conversations = ChatConversation::where('channel', 'business')->where('external_id', (string) $chatId)
            ->where('business_connection_id', (string) $connectionId)->latest('updated_at')->latest('id')->limit(5)->get();

        foreach ($conversations as $conversation) {
            $messages = $conversation->messages ?? [];
            $changed = false;
            foreach ($messages as $i => $m) {
                if (in_array($m['message_id'] ?? null, $ids, true)) {
                    $messages[$i]['deleted'] = true;
                    $changed = true;
                }
            }
            if ($changed) {
                $conversation->messages = $messages;
                $this->saveWithoutTouching($conversation);
            }
        }
    }

    public function groupMessage(array $message, bool $edited = false): void
    {
        $message = $this->clean($message);
        $chatId = (string) ($message['chat']['id'] ?? '');
        $internal = array_filter([config('services.telegram.chat_id'), config('services.telegram.alert_chat_id')]);
        if ($chatId === '' || in_array($chatId, array_map('strval', $internal), true)) {
            return;
        }

        $this->store($this->conversation('group', $message['chat'], null, $message['from'] ?? [], $edited), $message, 'member', $edited);
    }

    // ---------------------------------------------------------------------------

    private function store(ChatConversation $conversation, array $message, string $role, bool $edited): void
    {
        $entry = $this->entry($message, $role);
        $messages = $conversation->messages ?? [];

        if ($edited && $entry['message_id'] !== null) {
            foreach ($messages as $i => $m) {
                if (($m['message_id'] ?? null) === $entry['message_id']) {
                    $messages[$i] = ['at' => $m['at'] ?? $entry['at']] + $entry + ['edited' => true];
                    $conversation->messages = $messages;
                    $this->saveWithoutTouching($conversation); // an edit does not keep the session open

                    return;
                }
            }
        }
        if ($edited) {
            // The original is in an older conversation or arrived before recording: kept, but it is
            // not a new message (does not put the chat back on "Waiting for reply").
            $entry['edited'] = true;
            $entry['late_edit'] = true;
        }

        $messages[] = $entry;
        $conversation->messages = $messages;
        $conversation->message_count = count($messages);
        $conversation->save();
    }

    /** @return array<string, mixed> */
    private function entry(array $message, string $role): array
    {
        $entry = [
            'role' => $role,
            'sender' => $this->senderName($message),
            'message_id' => is_numeric($message['message_id'] ?? null) ? (int) $message['message_id'] : null,
            'at' => date('c', is_numeric($message['date'] ?? null) ? (int) $message['date'] : time()),
        ];

        return $entry + self::describe($message);
    }

    /**
     * What a message contains: {content} for text, otherwise {type, file_id, caption?}. Every kind is
     * described — files keep their Telegram file ID, anything else (contact, location, poll, a member
     * joining…) is stored by type with file_id null.
     *
     * @return array{content: string}|array{type: string, file_id: string|null, caption?: string}
     */
    public static function describe(array $message): array
    {
        if (isset($message['text']) && is_scalar($message['text'])) {
            return ['content' => mb_substr((string) $message['text'], 0, 4000)];
        }

        $caption = isset($message['caption']) && is_scalar($message['caption']) ? mb_substr((string) $message['caption'], 0, 1000) : null;
        $kinds = array_merge(TelegramBot::FILE_TYPES, TelegramBot::OTHER_TYPES, array_diff(array_map('strval', array_keys($message)), self::META));

        foreach ($kinds as $type) {
            if (! array_key_exists($type, $message) || in_array($type, self::META, true)) {
                continue;
            }
            $value = $message[$type];
            $file = $type === 'photo' && is_array($value) ? end($value) : $value; // a photo comes in sizes; keep the largest
            $fileId = is_array($file) && is_string($file['file_id'] ?? null) ? $file['file_id'] : null;

            return ['type' => mb_substr($type, 0, 40), 'file_id' => $fileId] + ($caption !== null ? ['caption' => $caption] : []);
        }

        return ['type' => 'other', 'file_id' => null];
    }

    private function senderName(array $message): string
    {
        if (isset($message['sender_chat']['title'])) {
            return (string) $message['sender_chat']['title']; // anonymous admin or linked channel
        }
        $from = $message['from'] ?? [];
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        return $name !== '' ? $name : (isset($from['username']) ? '@'.$from['username'] : 'Unknown');
    }

    private function conversation(string $channel, array $chat, ?TelegramBusinessConnection $connection, array $from, bool $edited = false): ChatConversation
    {
        $chatId = (string) $chat['id'];
        $title = $chat['title'] ?? (trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: (isset($chat['username']) ? '@'.$chat['username'] : null));

        $conversation = $this->latest($channel, $chatId, $connection?->connection_id);
        // An edit belongs to the latest conversation however old; a new message may start a new one.
        if ($conversation && ! $edited && ($conversation->message_count >= self::MAX_MESSAGES || $conversation->updated_at < now()->subHours(self::SESSION_HOURS))) {
            $conversation = null;
        }

        $conversation ??= new ChatConversation([
            'uuid' => (string) Str::uuid(),
            'channel' => $channel,
            'external_id' => $chatId,
            'business_connection_id' => $connection?->connection_id,
            'locale' => in_array($from['language_code'] ?? null, ['uz', 'ru', 'en'], true) ? $from['language_code'] : 'ru',
            'page' => $connection ? 'Account: '.$connection->label() : 'Group',
            'messages' => [],
            'message_count' => 0,
        ]);

        if ($title) {
            $conversation->title = mb_substr((string) $title, 0, 255); // follows renames
        }

        return $conversation;
    }

    /** Drop malformed fields (wrong types) so a broken update is recorded partly or skipped, never an error. */
    private function clean(array $message): array
    {
        foreach (['chat', 'from', 'sender_chat'] as $key) {
            if (array_key_exists($key, $message)) {
                $message[$key] = is_array($message[$key]) ? array_filter($message[$key], 'is_scalar') : [];
            }
        }
        foreach (['text', 'caption', 'business_connection_id', 'message_id', 'date'] as $key) {
            if (isset($message[$key]) && ! is_scalar($message[$key])) {
                unset($message[$key]);
            }
        }

        return $message;
    }

    private function saveWithoutTouching(ChatConversation $conversation): void
    {
        $conversation->timestamps = false;
        $conversation->save();
        $conversation->timestamps = true;
    }

    private function latest(string $channel, string $chatId, ?string $connectionId): ?ChatConversation
    {
        return ChatConversation::where('channel', $channel)
            ->where('external_id', $chatId)
            ->when($connectionId, fn ($q) => $q->where('business_connection_id', $connectionId))
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    /** Connections made before the webhook received business_connection updates are fetched from Telegram. */
    private function findConnection(string $id): ?TelegramBusinessConnection
    {
        if ($id === '') {
            return null;
        }

        $connection = TelegramBusinessConnection::firstWhere('connection_id', $id);
        // Asked at most every 10 minutes per unknown ID, so a failing lookup is not repeated for each message.
        if (! $connection && Cache::add('telegram-business-lookup:'.$id, true, now()->addMinutes(10))
            && is_array($data = $this->api->call('getBusinessConnection', ['business_connection_id' => $id])) && isset($data['id'])) {
            $connection = TelegramBusinessConnection::fromTelegram($data);
        }

        return $connection;
    }
}
