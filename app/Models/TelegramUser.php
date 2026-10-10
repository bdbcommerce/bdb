<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    public function isRegistered(): bool
    {
        return $this->registered_at !== null;
    }
}
