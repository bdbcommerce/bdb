<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Sales assistant settings (singleton, Admin → Sales assistant → Settings): the step-by-step sales conversation
 * in the bot chat and the Premium account (Business) chats. Prompt: resources/prompts/sales-assistant.md
 * (editable here) + SalesKnowledge.
 */
class SalesAssistantSetting extends Model
{
    public const TZ = 'Asia/Tashkent';

    public const MODES = ['on' => 'On', 'off' => 'Off'];

    public const MODELS = ['claude-haiku-5-5' => 'Claude Haiku 5.5 (fast, low cost)', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-opus-5-5' => 'Claude Opus 5.5 (best, highest cost)'];

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public const DEFAULT_DAYS = [1, 2, 3, 4, 5];

    /** What the assistant does when a customer writes outside working hours. */
    public const OFF_HOURS_MODES = [
        'note' => 'Send the working hours once, then stay silent until the office opens',
        'ai' => 'Answer as in working hours',
        'silent' => 'Send nothing (managers answer when the office opens)',
    ];

    protected $fillable = [
        'bot_mode', 'business_mode', 'wait_minutes', 'work_days', 'work_start', 'work_end',
        'remind_after_hours', 'close_after_hours', 'follow_up_days', 'model', 'prompt', 'employee_ids', 'platform_rules', 'dev_mode',
        'off_hours_mode', 'require_assignment', 'greeting_prompt', 'greeting_channels', 'employee_roles',
    ];

    /** Employee roles offered on the Telegram user page (editable in Settings, one per line). */
    public const DEFAULT_EMPLOYEE_ROLES = ['Sales', 'Support', 'Service', 'Finance', 'Accountant', 'Warehouse', 'Marketing', 'CEO'];

    /** Chats where a not-assigned contact gets the greeting. */
    public const GREETING_CHANNELS = ['bot' => 'Bot chat', 'business' => 'Premium-account chats'];

    protected function casts(): array
    {
        return ['greeting_channels' => 'array', 'require_assignment' => 'boolean', 'work_days' => 'array', 'wait_minutes' => 'integer', 'remind_after_hours' => 'integer', 'close_after_hours' => 'integer', 'follow_up_days' => 'integer', 'dev_mode' => 'boolean'];
    }

    public static function current(): self
    {
        try {
            $mode = config('services.telegram.sales_assistant', 'on');

            return static::firstOrCreate(['id' => 1], ['bot_mode' => $mode, 'business_mode' => $mode])->refresh();
        } catch (Throwable) {
            return new static(['bot_mode' => 'off', 'business_mode' => 'off']);
        }
    }

    public static function defaultPrompt(): string
    {
        return (string) file_get_contents(resource_path('prompts/sales-assistant.md'));
    }

    public function promptText(): string
    {
        return filled($this->prompt) ? (string) $this->prompt : self::defaultPrompt();
    }

    /** @return list<int> */
    public function workDays(): array
    {
        $days = array_values(array_intersect(array_keys(self::DAYS), array_map('intval', (array) ($this->work_days ?? self::DEFAULT_DAYS))));

        return $days ?: self::DEFAULT_DAYS;
    }

    public function isWorkingTime(?Carbon $at = null): bool
    {
        $at = ($at ?? now())->copy()->setTimezone(self::TZ);
        $time = $at->format('H:i');

        return in_array($at->isoWeekday(), $this->workDays(), true) && $time >= (string) $this->work_start && $time < (string) $this->work_end;
    }

    /** The AI team talks only with assigned clients; others get the greeting (null = config, on). */
    public function requiresAssignment(): bool
    {
        return $this->require_assignment ?? (bool) config('services.telegram.sales_require_assignment', true);
    }

    /**
     * What the AI does with this contact: talk (an assigned client, or assignment not required), greet (the greeting
     * once, then silence: not assigned yet, or a supplier) or silent (an employee — enabledFor already says no).
     */
    public function gate(?TelegramUser $user): string
    {
        if ($user?->contact_type === TelegramUser::TYPE_EMPLOYEE) {
            return 'silent';
        }
        if (! $this->requiresAssignment()) {
            return 'talk';
        }

        return match ($user?->contact_type) {
            TelegramUser::TYPE_CLIENT => 'talk',
            default => 'greet', // supplier, or not assigned yet
        };
    }

    /** @return list<string> */
    public function greetingChannels(): array
    {
        return $this->greeting_channels === null
            ? array_keys(self::GREETING_CHANNELS)
            : array_values(array_intersect(array_keys(self::GREETING_CHANNELS), (array) $this->greeting_channels));
    }

    public static function defaultGreetingPrompt(): string
    {
        return (string) file_get_contents(resource_path('prompts/sales-greeting.md'));
    }

    public function greetingPromptText(): string
    {
        return filled($this->greeting_prompt) ? (string) $this->greeting_prompt : self::defaultGreetingPrompt();
    }

    /** @return list<string> */
    public function employeeRoles(): array
    {
        $roles = collect(preg_split('/\R/u', (string) $this->employee_roles))->map(fn ($r) => trim(mb_substr((string) $r, 0, 60)))->filter()->unique()->values()->all();

        return $roles ?: self::DEFAULT_EMPLOYEE_ROLES;
    }

    public function offHoursMode(): string
    {
        // Not chosen yet: the config default (note; the test suite uses ai, the earlier behaviour).
        $mode = (string) ($this->off_hours_mode ?? config('services.telegram.sales_off_hours', 'note'));

        return array_key_exists($mode, self::OFF_HOURS_MODES) ? $mode : 'note';
    }

    /** The start of the next working period after $at (Tashkent time). */
    public function nextOpening(?Carbon $at = null): Carbon
    {
        $at = ($at ?? now())->copy()->setTimezone(self::TZ);
        for ($i = 0; $i <= 7; $i++) {
            $day = $at->copy()->startOfDay()->addDays($i);
            $open = $day->copy()->setTimeFromTimeString((string) ($this->work_start ?: '09:00'));
            if (in_array($day->isoWeekday(), $this->workDays(), true) && $open->gt($at)) {
                return $open;
            }
        }

        return $at->copy()->addDay();
    }

    /** "Mon–Fri 09:00–18:00" in the customer's language (chat.sales_days_short). */
    public function hoursText(string $locale): string
    {
        $names = (array) __('chat.sales_days_short', [], $locale);
        $groups = [];
        foreach ($this->workDays() as $d) {
            $last = array_key_last($groups);
            if ($last !== null && end($groups[$last]) === $d - 1) {
                $groups[$last][] = $d;
            } else {
                $groups[] = [$d];
            }
        }
        $name = fn (int $d) => (string) ($names[$d] ?? self::DAYS[$d]);
        $text = implode(', ', array_map(fn ($g) => count($g) > 2 ? $name($g[0]).'–'.$name(end($g)) : implode(', ', array_map($name, $g)), $groups));

        return $text.' '.$this->work_start.'–'.$this->work_end;
    }

    /** The polite note sent once outside working hours, in the customer's language. */
    public function offHoursNote(string $locale, ?Carbon $now = null): string
    {
        $locale = in_array($locale, ['uz', 'ru', 'en'], true) ? $locale : 'ru';
        $now = ($now ?? now())->copy()->setTimezone(self::TZ);
        $open = $this->nextOpening($now);
        $days = (int) $now->copy()->startOfDay()->diffInDays($open->copy()->startOfDay());
        $when = match ($days) {
            0 => __('chat.sales_today', [], $locale),
            1 => __('chat.sales_tomorrow', [], $locale),
            default => (string) (((array) __('chat.sales_on_day', [], $locale))[$open->isoWeekday()] ?? ''),
        };

        return __('chat.sales_off_hours', ['hours' => $this->hoursText($locale), 'when' => $when, 'time' => $open->format('H:i')], $locale);
    }

    /** Whether the assistant talks to this customer in this channel (telegram | business). Employees never. */
    public function enabledFor(string $channel, ?TelegramUser $user, ?string $telegramId = null, ?string $username = null): bool
    {
        $mode = $channel === 'business' ? $this->business_mode : $this->bot_mode;
        if ($mode === 'off' || $this->isEmployee($user, $telegramId, $username)) {
            return false;
        }

        return $mode === 'on';
    }

    public function isEmployee(?TelegramUser $user, ?string $telegramId = null, ?string $username = null): bool
    {
        if ($user?->is_employee || $user?->contact_type === TelegramUser::TYPE_EMPLOYEE) {
            return true;
        }
        // A type an admin chose (client / supplier) wins over the extra employee list below.
        if ($user?->contact_type !== null && $user?->contact_type !== '') {
            return false;
        }
        $ids = $this->employeeList();
        $id = trim((string) ($telegramId ?? $user?->telegram_id));
        $name = mb_strtolower(ltrim(trim((string) ($username ?? $user?->username)), '@'));

        return ($id !== '' && in_array($id, $ids, true)) || ($name !== '' && in_array('@'.$name, $ids, true));
    }

    /** The extra employee list: Telegram IDs as digits, usernames as lower-case "@name". @return list<string> */
    public function employeeList(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) $this->employee_ids) as $item) {
            $item = trim($item);
            if (preg_match('/^-?\d{3,20}$/', $item)) {
                $out[] = $item;
            } elseif (preg_match('/^@?([A-Za-z][A-Za-z0-9_]{3,31})$/', $item, $m)) {
                $out[] = '@'.mb_strtolower($m[1]);
            }
        }

        return array_values(array_unique($out));
    }

    /** The full system prompt the assistant gets (Preview prompt shows exactly this). */
    /**
     * $as / $rolePrompt: another AI employee or prompt text to try (the sandbox); default the live sales consultant
     * with the published prompt. The sales playbook is added for sales consultants only.
     */
    public function systemPrompt(?AiEmployee $as = null, ?string $rolePrompt = null): string
    {
        $days = implode(', ', array_map(fn ($d) => self::DAYS[$d], $this->workDays()));

        // The live sales consultant's card (platform rules, name, temperament, role prompt) comes first; the owner's
        // sales playbook follows. Without a live sales consultant: the playbook alone, as before.
        $employee = $as ?? rescue(fn () => AiEmployee::active('sales'), null, false);
        $head = match (true) {
            ! $employee => trim($this->promptText()),
            $employee->kind === 'sales' => $employee->promptLayers($rolePrompt)."\n\n# SALES PLAYBOOK (the owner's rules for the sales conversation)\n".trim($this->promptText()),
            default => $employee->promptLayers($rolePrompt),
        };

        return $head
            ."\n\n# WORKING HOURS (Tashkent time)\n{$days} {$this->work_start}–{$this->work_end}. In Business chats during working hours a manager gets {$this->wait_minutes} minutes to answer first; outside working hours ".($this->offHoursMode() === 'ai' ? 'you answer at once.' : 'customers get a fixed note with these hours; the managers answer them when the office opens.')
            ."\n\n# KNOWLEDGE (use only this; never add facts)\n".SalesKnowledge::forPrompt();
    }
}
