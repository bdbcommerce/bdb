<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram bot customer and their registration progress:
 * language → name → phone (the number linked to their Telegram account) → done.
 */
class TelegramUser extends Model
{
    public const STEP_LANGUAGE = 'language';

    /** Language picked, not registered: questions get short answers plus an invitation to register. */
    public const STEP_GUEST = 'guest';

    public const STEP_NAME = 'name';

    public const STEP_PHONE = 'phone';

    public const STEP_DONE = 'done';

    protected $fillable = [
        'telegram_id', 'username', 'locale', 'step', 'name', 'profile_name', 'phone', 'company', 'notes',
        'ask_registration', 'source', 'registered_at', 'phone_verified_at', 'is_employee',
        'contact_type', 'employee_role', 'ai_employee_id', 'assigned_at', 'greeted_at', 'assignment_requested_at',
    ];

    public const TYPE_CLIENT = 'client';

    public const TYPE_SUPPLIER = 'supplier';

    public const TYPE_EMPLOYEE = 'employee';

    /**
     * Who the contact is (null = not assigned yet: the AI greets once and waits). Clients get the AI team, suppliers
     * only the greeting, employees nothing.
     */
    public const TYPES = [self::TYPE_CLIENT => 'Client', self::TYPE_SUPPLIER => 'Supplier', self::TYPE_EMPLOYEE => 'Employee'];

    public function aiEmployee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class);
    }

    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('contact_type');
    }

    /**
     * A guess from what we already know, for the admin to confirm: [type, reason] or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function suggestedType(): ?array
    {
        if (rescue(fn () => SalesAssistantSetting::current()->isEmployee(null, (string) $this->telegram_id, $this->username), false, false)) {
            return [self::TYPE_EMPLOYEE, 'in the employee list of the sales assistant settings'];
        }
        $person = rescue(fn () => ClientPerson::current($this), null, false);
        if ($person) {
            return [self::TYPE_CLIENT, 'has a client portrait'.($person->client ? ' at '.$person->client->name : '')];
        }
        $leads = $this->exists ? $this->conversations()->whereNotNull('chat_lead_id')->count() : 0;
        if ($leads > 0) {
            return [self::TYPE_CLIENT, "left {$leads} request(s) before"];
        }
        if ($this->phone && (ChatLead::where('phone', $this->phone)->exists())) {
            return [self::TYPE_CLIENT, 'a request with the same phone exists'];
        }

        return null;
    }

    /** Where the customer was first seen. */
    public const SOURCES = ['bot' => 'Bot', 'business' => 'Premium account'];

    protected $attributes = ['step' => self::STEP_LANGUAGE];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'greeted_at' => 'datetime', 'assignment_requested_at' => 'datetime', 'registered_at' => 'datetime', 'phone_verified_at' => 'datetime', 'ask_registration' => 'boolean', 'is_employee' => 'boolean'];
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

    /** The phone came from Telegram's contact button (or an admin): safe to give to sales for a call. */
    public function hasVerifiedPhone(): bool
    {
        return filled($this->phone) && $this->phone_verified_at !== null;
    }

    public function isRegistered(): bool
    {
        return $this->registered_at !== null;
    }
}
