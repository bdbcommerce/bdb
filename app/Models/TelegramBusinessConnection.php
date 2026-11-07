<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Telegram Premium account that connected the bot through Telegram Business
 * (Settings → Telegram Business → Chatbots). The bot then receives that account's
 * private chats as business_message updates; they are recorded, not answered.
 */
class TelegramBusinessConnection extends Model
{
    protected $fillable = ['connection_id', 'user_id', 'user_chat_id', 'name', 'username', 'is_enabled', 'can_reply'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'can_reply' => 'boolean'];
    }

    /** Save a BusinessConnection object as Telegram sends it. */
    public static function fromTelegram(array $connection): ?self
    {
        // Wrong-typed fields are treated as missing.
        $str = fn ($v) => is_scalar($v) && (string) $v !== '' ? (string) $v : null;
        $user = is_array($connection['user'] ?? null) ? $connection['user'] : [];

        if (($id = $str($connection['id'] ?? null)) === null) {
            return null;
        }

        return static::updateOrCreate(['connection_id' => $id], [
            'user_id' => $str($user['id'] ?? null) ?? '',
            'user_chat_id' => $str($connection['user_chat_id'] ?? null),
            'name' => mb_substr(trim(($str($user['first_name'] ?? null) ?? '').' '.($str($user['last_name'] ?? null) ?? '')), 0, 255) ?: null,
            'username' => $str($user['username'] ?? null),
            'is_enabled' => (bool) ($connection['is_enabled'] ?? true),
            // Bot API 9+: rights.can_reply; older: can_reply.
            'can_reply' => (bool) (is_array($connection['rights'] ?? null) ? ($connection['rights']['can_reply'] ?? false) : ($connection['can_reply'] ?? false)),
        ]);
    }

    public function label(): string
    {
        return $this->name ?? ($this->username ? '@'.$this->username : 'Premium account');
    }
}
