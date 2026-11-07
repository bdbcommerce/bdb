<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram bot customer and their registration progress:
 * language → name → phone (the number linked to their Telegram account) → done.
 */
class TelegramUser extends Model
{
    public const STEP_LANGUAGE = 'language';

    public const STEP_NAME = 'name';

    public const STEP_PHONE = 'phone';

    public const STEP_DONE = 'done';

    protected $fillable = [
        'telegram_id', 'username', 'locale', 'step', 'name', 'profile_name', 'phone', 'company', 'notes',
        'ask_registration', 'source', 'registered_at',
    ];

    /** Where the customer was first seen. */
    public const SOURCES = ['bot' => 'Bot', 'business' => 'Premium account'];

    protected $attributes = ['step' => self::STEP_LANGUAGE];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'ask_registration' => 'boolean'];
    }

    public function scopeRegistered(Builder $query): void
    {
        $query->whereNotNull('registered_at');
    }

    /**
     * Chats with this customer: with the bot, and with the connected Premium account
     * (a private chat's ID is the user's Telegram ID). Groups are not included.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'external_id', 'telegram_id')->whereIn('channel', ['telegram', 'business']);
    }

    /** Create or refresh a customer from a Telegram user object (from a bot or Business chat). */
    public static function fromTelegram(array $from, string $source): ?self
    {
        if (! is_scalar($from['id'] ?? null) || (string) $from['id'] === '') {
            return null;
        }

        $user = static::firstOrNew(['telegram_id' => (string) $from['id']]);
        if (! $user->exists) {
            $user->source = $source;
        }
        if (is_string($from['username'] ?? null)) {
            $user->username = $from['username'];
        }
        $profile = trim(implode(' ', array_filter([$from['first_name'] ?? null, $from['last_name'] ?? null], 'is_string')));
        if ($profile !== '') {
            $user->profile_name = mb_substr($profile, 0, 255);
        }
        $user->save();

        return $user;
    }

    /**
     * A phone number as +digits: separators removed, a 9-digit local Uzbek number gets +998.
     * Null when it is not a phone number (other countries need their code).
     */
    public static function normalizePhone(?string $input): ?string
    {
        $phone = preg_replace('/[\s()\-.]/', '', trim((string) $input));
        if (preg_match('/^\d{9}$/', $phone)) {
            return '+998'.$phone;
        }
        if (preg_match('/^\+?(\d{11,15})$/', $phone, $m) || preg_match('/^\+(\d{7,10})$/', $phone, $m)) {
            return '+'.$m[1];
        }

        return null;
    }

    /** The registered name, else the Telegram profile name, else @username. */
    public function displayName(): ?string
    {
        return $this->name ?? $this->profile_name ?? ($this->username ? '@'.$this->username : null);
    }

    public function isRegistered(): bool
    {
        return $this->registered_at !== null;
    }
}
