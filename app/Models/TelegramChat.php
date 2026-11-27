<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A Telegram chat the bot has seen: a group / supergroup / channel it is in, or a private chat with it.
 * Filled by TelegramBot::handle from every update (also the invoice source chat and the sales / alerts
 * chats, which are never recorded as conversations) and by `my_chat_member` (bot added / removed).
 * Used by the chat and group pickers in the admin forms (TelegramChat::options).
 */
class TelegramChat extends Model
{
    public const GROUP_TYPES = ['group', 'supergroup', 'channel'];

    /** A chat seen again within this many minutes is not written again. */
    private const SEEN_EVERY_MINUTES = 10;

    protected $fillable = ['chat_id', 'type', 'title', 'username', 'bot_status', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** Remember a chat from a Telegram `chat` object; $botStatus from my_chat_member is always written. */
    public static function see(mixed $chat, ?string $botStatus = null): void
    {
        if (! is_array($chat) || ! is_scalar($chat['id'] ?? null) || ! is_string($chat['type'] ?? null)) {
            return;
        }
        $id = (string) $chat['id'];
        if (! preg_match('/^-?\d{1,20}$/', $id) || ($botStatus === null && ! Cache::add('tg-chat-seen:'.$id, true, now()->addMinutes(self::SEEN_EVERY_MINUTES)))) {
            return;
        }
        $title = is_string($chat['title'] ?? null) ? $chat['title']
            : trim(implode(' ', array_filter([$chat['first_name'] ?? null, $chat['last_name'] ?? null], 'is_string')));
        $row = static::firstOrNew(['chat_id' => $id]);
        $row->fill(array_filter([
            'type' => mb_substr($chat['type'], 0, 16),
            'title' => $title !== '' ? mb_substr($title, 0, 255) : null,
            'username' => is_string($chat['username'] ?? null) ? mb_substr($chat['username'], 0, 255) : null,
            'bot_status' => $botStatus !== null ? mb_substr($botStatus, 0, 16) : null,
        ], fn ($v) => $v !== null) + ['last_seen_at' => now()])->save();
    }

    /** The bot is no longer in this group. */
    public function isGone(): bool
    {
        return in_array($this->bot_status, ['left', 'kicked'], true);
    }

    /**
     * Picker choices, id => label, newest first.
     * groups — groups the bot is in (also groups only known from recorded conversations);
     * chats  — private chats with the bot (people the bot can write to);
     * people — Telegram contacts (anyone who wrote to the bot or the Premium account).
     *
     * @return Collection<string, string>
     */
    public static function options(string $kind): Collection
    {
        if ($kind === 'groups') {
            $known = static::whereIn('type', self::GROUP_TYPES)->orderByDesc('last_seen_at')->limit(300)->get()
                ->mapWithKeys(fn (self $c) => [$c->chat_id => ($c->title ?: $c->chat_id).($c->isGone() ? ' (bot removed)' : '')]);
            $recorded = ChatConversation::where('channel', 'group')->whereNotNull('external_id')->orderByDesc('id')->limit(300)->get(['external_id', 'title'])
                ->unique('external_id')->mapWithKeys(fn ($c) => [(string) $c->external_id => $c->title ?: (string) $c->external_id]);

            return $known->union($recorded);
        }

        $names = fn (Collection $ids) => TelegramUser::whereIn('telegram_id', $ids)->get()->keyBy('telegram_id');
        if ($kind === 'chats') {
            $chats = static::where('type', 'private')->orderByDesc('last_seen_at')->limit(500)->get();
            $users = $names($chats->pluck('chat_id'));
            $list = $chats->mapWithKeys(fn (self $c) => [$c->chat_id => self::personLabel($users[$c->chat_id] ?? null, $c->title, $c->username)]);
            // Contacts who wrote to the bot before the list existed.
            $older = TelegramUser::whereNotNull('bot_chat_at')->orWhere('source', 'bot')->orderByDesc('updated_at')->limit(500)->get()
                ->mapWithKeys(fn (TelegramUser $u) => [(string) $u->telegram_id => self::personLabel($u, null, null)]);

            return $list->union($older);
        }

        return TelegramUser::orderByDesc('updated_at')->limit(1000)->get()
            ->mapWithKeys(fn (TelegramUser $u) => [(string) $u->telegram_id => self::personLabel($u, null, null)]);
    }

    private static function personLabel(?TelegramUser $user, ?string $title, ?string $username): string
    {
        $name = $user?->displayName() ?: $title;
        $at = $user?->username ?: $username;

        return trim(($name ?: 'Telegram').($at && $at !== $name ? ' @'.$at : '').($user?->is_employee || $user?->contact_type === 'employee' ? ' · employee' : ''));
    }
}
