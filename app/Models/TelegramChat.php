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

    /** What a group is used for (TelegramChat::roles). Sales and alerts chats come from .env. */
    public const ROLES = [
        'sales' => 'Sales chat — leads, requests, notices (TELEGRAM_CHAT_ID in .env)',
        'alerts' => 'Alerts chat — errors and warnings (TELEGRAM_ALERT_CHAT_ID in .env)',
        'invoice_source' => 'Invoice source — the bot reads invoice photos here; the weekly sales report and AI notes go here too',
        'invoice_chat' => 'Invoice chat — read invoices are posted here (accountants)',
        'json' => 'JSON recipient — invoice JSON, errors and the daily sales report',
    ];

    /** Short role names for badges. */
    public const ROLE_NAMES = ['sales' => 'Sales chat', 'alerts' => 'Alerts chat', 'invoice_source' => 'Invoice source', 'invoice_chat' => 'Invoice chat', 'json' => 'JSON recipient'];

    /** Roles an admin sets from the group page (stored in the invoice parser settings). */
    public const SETTABLE_ROLES = ['invoice_source' => 'source_chat_id', 'invoice_chat' => 'target_chat_id'];

    protected $fillable = ['chat_id', 'type', 'title', 'username', 'bot_status', 'is_recorded', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'is_recorded' => 'boolean'];
    }

    public function isGroup(): bool
    {
        return in_array($this->type, self::GROUP_TYPES, true);
    }

    /** @return list<string> keys of ROLES this chat has */
    public function roles(): array
    {
        $invoice = InvoiceParserSetting::current();
        $map = ['sales' => config('services.telegram.chat_id'), 'alerts' => config('services.telegram.alert_chat_id'),
            'invoice_source' => $invoice->source_chat_id, 'invoice_chat' => $invoice->target_chat_id, 'json' => $invoice->json_chat_id];

        return array_keys(array_filter($map, fn ($id) => filled($id) && trim((string) $id) === $this->chat_id));
    }

    /**
     * Sales and alerts chats are never recorded as conversations, whatever the switch says; the invoice source
     * only while the invoice parser is on (it takes those messages first).
     *
     * @return list<string>
     */
    public function neverRecordedRoles(): array
    {
        $roles = array_intersect($this->roles(), ['sales', 'alerts', 'invoice_source']);
        if (! InvoiceParserSetting::current()->enabled) {
            $roles = array_diff($roles, ['invoice_source']);
        }

        return array_values($roles);
    }

    public function neverRecorded(): bool
    {
        return $this->neverRecordedRoles() !== [];
    }

    /** A group whose messages the admin chose not to record. */
    public static function isMuted(string $chatId): bool
    {
        return static::where('chat_id', $chatId)->where('is_recorded', false)->exists();
    }

    /** All recorded sessions of this group, newest first. */
    public function conversations()
    {
        return ChatConversation::where('channel', 'group')->where('external_id', $this->chat_id);
    }

    /**
     * People who wrote in the group (from its recorded sessions): sender ID => name, messages, last time, contact.
     *
     * @return Collection<string, array{name: string, messages: int, last: ?string, user: ?TelegramUser}>
     */
    public function members(int $sessions = 50): Collection
    {
        $people = [];
        foreach ($this->conversations()->orderByDesc('id')->limit($sessions)->get(['id', 'messages']) as $c) {
            foreach ((array) $c->messages as $m) {
                $id = is_array($m) && is_scalar($m['sender_id'] ?? null) ? (string) $m['sender_id'] : null;
                if ($id === null || $id === '' || ($m['role'] ?? null) !== 'member') {
                    continue;
                }
                $people[$id] ??= ['name' => '', 'messages' => 0, 'last' => null];
                $people[$id]['messages']++;
                $people[$id]['name'] = $people[$id]['name'] ?: (is_string($m['sender'] ?? null) ? mb_substr($m['sender'], 0, 100) : '');
                $at = is_string($m['at'] ?? null) && strtotime($m['at']) !== false ? $m['at'] : null;
                if ($at && ($people[$id]['last'] === null || $at > $people[$id]['last'])) {
                    $people[$id]['last'] = $at;
                }
            }
        }
        $users = TelegramUser::whereIn('telegram_id', array_keys($people))->get()->keyBy('telegram_id');

        return collect($people)->map(fn ($p, $id) => $p + ['user' => $users[$id] ?? null])->sortByDesc('messages');
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
