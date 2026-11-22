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

    protected $fillable = [
        'bot_mode', 'business_mode', 'wait_minutes', 'work_days', 'work_start', 'work_end',
        'remind_after_hours', 'close_after_hours', 'follow_up_days', 'model', 'prompt', 'employee_ids', 'platform_rules', 'dev_mode',
    ];

    protected function casts(): array
    {
        return ['work_days' => 'array', 'wait_minutes' => 'integer', 'remind_after_hours' => 'integer', 'close_after_hours' => 'integer', 'follow_up_days' => 'integer', 'dev_mode' => 'boolean'];
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
        if ($user?->is_employee) {
            return true;
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
        $employee = $as ?? rescue(fn () => AiEmployee::live('sales'), null, false);
        $head = match (true) {
            ! $employee => trim($this->promptText()),
            $employee->kind === 'sales' => $employee->promptLayers($rolePrompt)."\n\n# SALES PLAYBOOK (the owner's rules for the sales conversation)\n".trim($this->promptText()),
            default => $employee->promptLayers($rolePrompt),
        };

        return $head
            ."\n\n# WORKING HOURS (Tashkent time)\n{$days} {$this->work_start}–{$this->work_end}. In Business chats during working hours a manager gets {$this->wait_minutes} minutes to answer first; outside working hours you answer at once."
            ."\n\n# KNOWLEDGE (use only this; never add facts)\n".SalesKnowledge::forPrompt();
    }
}
