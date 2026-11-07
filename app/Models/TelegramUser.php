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

    protected $fillable = ['telegram_id', 'username', 'locale', 'step', 'name', 'phone', 'registered_at'];

    protected $attributes = ['step' => self::STEP_LANGUAGE];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime'];
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

    public function isRegistered(): bool
    {
        return $this->registered_at !== null;
    }
}
