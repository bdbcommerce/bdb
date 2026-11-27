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
        'ai' => 'Answer fully, as in working hours (what a manager must confirm waits for the morning)',
        'silent' => 'Send nothing (managers answer when the office opens)',
    ];

    protected $fillable = [
        'bot_mode', 'business_mode', 'wait_minutes', 'work_days', 'work_start', 'work_end',
        'remind_after_hours', 'close_after_hours', 'follow_up_days', 'model', 'prompt', 'employee_ids', 'platform_rules', 'dev_mode',
        'off_hours_mode', 'require_assignment', 'greeting_prompt', 'greeting_channels', 'employee_roles',
        'expert_remind_minutes', 'expert_escalate_minutes', 'claude_data', 'new_contact_mode', 'large_order_sum', 'lead_remind_minutes', 'draft_nudge_days', 'reorder_hints',
        'holidays', 'morning_summary', 'morning_summary_sent_on',
    ];

    /** A draft order the customer left: the AI writes once after this many days (working hours). */
    public const DRAFT_NUDGE_DAYS = 3;

    /** A request from the assistant nobody took ("I take it") or marked handled: the main admin is reminded after this (working minutes). */
    public const LEAD_REMIND_MINUTES = 15;

    /** A request at least this big (catalog prices × quantity, sum) goes to a manager at once. */
    public const LARGE_ORDER_SUM = 10_000_000;

    /**
     * What the AI does with a contact who is not assigned yet (and suppliers). The sales chat is asked to assign them
     * in every mode; "Greet new contacts in" picks the chats where a greeting may be sent.
     */
    public const NEW_CONTACT_MODES = [
        'smart' => ['Greet if new, else just create', 'A new customer gets one greeting, then the AI waits. A chat that continues an earlier talk (a reply to an old message, or the AI sees it refers to something it cannot see) gets no greeting: the contact is only created and left to the managers.'],
        'always' => ['Greet, then wait', 'Every new contact gets one greeting, also when the chat seems to continue an earlier talk. Never in the middle of a manager\'s talk.'],
        'create' => ['Just create, no greeting', 'Nothing is sent: the contact is created and the sales chat is asked to assign them.'],
    ];

    /**
     * Customer data a sales assistant turn may send to Claude (Settings → Data sent to Claude); all on by default.
     * Off = left out of the request; the AI is told what it cannot see.
     */
    public const CLAUDE_DATA = [
        'phone' => ['Phone numbers', 'Off: the customer card says only whether a phone is known, and phones in messages are sent as [phone]. A phone the customer types is still saved for the request by Laravel itself.'],
        'username' => ['Telegram @username', 'Off: only the Telegram display name is sent.'],
        'photos' => ['Photos and PDF files the customer sends', 'Off: the AI sees only "[photo]" or "[a file]" and asks the customer to describe it, or leaves it to a manager. An INN typed in a message is still found.'],
        'portrait' => ['Client portrait and companies', 'Off: the portrait (position, company, part in decisions, style) and the linked companies (what they usually order, the sales plan) are not sent. Do not contact still applies.'],
    ];

    /** Employee roles offered on the Telegram user page (editable in Settings, one per line). */
    public const DEFAULT_EMPLOYEE_ROLES = ['Sales', 'Support', 'Service', 'Finance', 'Accountant', 'Warehouse', 'Marketing', 'CEO'];

    /** Chats where a not-assigned contact gets the greeting. */
    public const GREETING_CHANNELS = ['bot' => 'Bot chat', 'business' => 'Premium-account chats'];

    protected function casts(): array
    {
        return ['greeting_channels' => 'array', 'require_assignment' => 'boolean', 'work_days' => 'array', 'wait_minutes' => 'integer', 'remind_after_hours' => 'integer', 'close_after_hours' => 'integer', 'follow_up_days' => 'integer', 'dev_mode' => 'boolean', 'expert_remind_minutes' => 'integer', 'expert_escalate_minutes' => 'integer', 'claude_data' => 'array', 'large_order_sum' => 'integer', 'lead_remind_minutes' => 'integer', 'draft_nudge_days' => 'integer', 'reorder_hints' => 'boolean', 'morning_summary' => 'boolean', 'morning_summary_sent_on' => 'date'];
    }

    /** A request nobody took: minutes (working time) before the main admin is reminded. */
    public function leadRemindMinutes(): int
    {
        return max(1, (int) ($this->lead_remind_minutes ?? self::LEAD_REMIND_MINUTES));
    }

    /** 0 = no reminder about quiet drafts. */
    public function draftNudgeDays(): int
    {
        return $this->draft_nudge_days ?? self::DRAFT_NUDGE_DAYS;
    }

    /** 0 = no handover by the amount. */
    public function largeOrderSum(): int
    {
        return $this->large_order_sum ?? self::LARGE_ORDER_SUM;
    }

    public function newContactMode(): string
    {
        return array_key_exists((string) $this->new_contact_mode, self::NEW_CONTACT_MODES) ? $this->new_contact_mode : 'smart';
    }

    /** May a sales assistant turn send this kind of customer data to Claude (CLAUDE_DATA key)? */
    public function sendsToClaude(string $key): bool
    {
        return (bool) (((array) ($this->claude_data ?? []))[$key] ?? true);
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

        return $this->isWorkingDay($at) && $time >= (string) $this->work_start && $time < (string) $this->work_end;
    }

    /** A working weekday that is not a holiday (Tashkent date). */
    public function isWorkingDay(Carbon $day): bool
    {
        $day = $day->copy()->setTimezone(self::TZ);

        return in_array($day->isoWeekday(), $this->workDays(), true) && $this->holidayOn($day) === null;
    }

    /**
     * Holidays from the settings, one per line: "2027-03-21 Navruz", a range "2027-03-20 – 2027-03-23 Navruz",
     * dates also as 21.03.2027. Lines that do not parse are skipped (the settings form refuses them).
     *
     * @return list<array{from: string, to: string, name: string}>
     */
    public function holidays(): array
    {
        return array_values(array_filter(array_map(fn ($line) => self::parseHoliday($line), preg_split('/\R/u', (string) $this->holidays))));
    }

    /** One holiday line → {from, to (Y-m-d), name}; null for an empty or unreadable line or a range over 31 days. */
    public static function parseHoliday(string $line): ?array
    {
        $date = '(\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}\.\d{4})';
        if (! preg_match('/^\s*'.$date.'(?:\s*(?:-|–|—|\.\.)\s*'.$date.')?(?:\s+(.*))?\s*$/u', $line, $m)) {
            return null;
        }
        $parse = function (string $d): ?string {
            if (str_contains($d, '.')) {
                [$day, $month, $year] = array_map('intval', explode('.', $d));
            } else {
                [$year, $month, $day] = array_map('intval', explode('-', $d));
            }

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        };
        $from = $parse($m[1]);
        $to = ($m[2] ?? '') !== '' ? $parse($m[2]) : $from;
        if (! $from || ! $to || $to < $from || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 30) {
            return null;
        }

        return ['from' => $from, 'to' => $to, 'name' => mb_substr(trim($m[3] ?? '', " \t-–—:"), 0, 60)];
    }

    /** The holiday's name on that day ('' when it has none), or null on an ordinary day. */
    public function holidayOn(Carbon $day): ?string
    {
        $date = $day->copy()->setTimezone(self::TZ)->toDateString();
        foreach ($this->holidays() as $h) {
            if ($date >= $h['from'] && $date <= $h['to']) {
                return $h['name'];
            }
        }

        return null;
    }

    /** @return list<string> holidays from today on, for the AI ("21.03 Navruz"), at most 5 */
    public function upcomingHolidays(int $days = 60): array
    {
        $today = now()->setTimezone(self::TZ)->toDateString();
        $until = now()->setTimezone(self::TZ)->addDays($days)->toDateString();

        return collect($this->holidays())->filter(fn ($h) => $h['to'] >= $today && $h['from'] <= $until)->sortBy('from')->take(5)
            ->map(fn ($h) => Carbon::parse($h['from'])->format('d.m').($h['to'] !== $h['from'] ? '–'.Carbon::parse($h['to'])->format('d.m') : '').($h['name'] !== '' ? ' '.$h['name'] : ''))
            ->values()->all();
    }

    /** The end of the last working period before $at (Tashkent time). */
    public function previousClosing(?Carbon $at = null): Carbon
    {
        $at = ($at ?? now())->copy()->setTimezone(self::TZ);
        for ($i = 0; $i <= 400; $i++) { // holidays can chain
            $day = $at->copy()->startOfDay()->subDays($i);
            $close = $day->copy()->setTimeFromTimeString((string) ($this->work_end ?: '18:00'));
            if ($this->isWorkingDay($day) && $close->lte($at)) {
                return $close;
            }
        }

        return $at->copy()->subDay();
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
        // Not chosen yet: the config default (ai — answer fully; SALES_OFF_HOURS can choose note or silent).
        $mode = (string) ($this->off_hours_mode ?? config('services.telegram.sales_off_hours', 'ai'));

        return array_key_exists($mode, self::OFF_HOURS_MODES) ? $mode : 'ai';
    }

    /** Expert questions: a reminder to the expert after this many working minutes, then the main admin. */
    public const EXPERT_REMIND_MINUTES = 30;

    public const EXPERT_ESCALATE_MINUTES = 60;

    public function expertRemindMinutes(): int
    {
        return max(1, (int) ($this->expert_remind_minutes ?? self::EXPERT_REMIND_MINUTES));
    }

    public function expertEscalateMinutes(): int
    {
        return max($this->expertRemindMinutes() + 1, (int) ($this->expert_escalate_minutes ?? self::EXPERT_ESCALATE_MINUTES));
    }

    /** Minutes of working time between two moments (Tashkent working days and hours). */
    public function workingMinutesBetween(Carbon $from, Carbon $to): int
    {
        $from = $from->copy()->setTimezone(self::TZ);
        $to = $to->copy()->setTimezone(self::TZ);
        $minutes = 0;
        for ($day = $from->copy()->startOfDay(), $i = 0; $day->lte($to) && $i < 400; $day->addDay(), $i++) {
            if (! $this->isWorkingDay($day)) {
                continue;
            }
            $open = $day->copy()->setTimeFromTimeString((string) ($this->work_start ?: '09:00'));
            $close = $day->copy()->setTimeFromTimeString((string) ($this->work_end ?: '18:00'));
            $start = $from->gt($open) ? $from : $open;
            $end = $to->lt($close) ? $to : $close;
            if ($end->gt($start)) {
                $minutes += intdiv((int) $start->diffInSeconds($end, true), 60);
            }
        }

        return $minutes;
    }

    /** The start of the next working period after $at (Tashkent time). */
    public function nextOpening(?Carbon $at = null): Carbon
    {
        $at = ($at ?? now())->copy()->setTimezone(self::TZ);
        for ($i = 0; $i <= 400; $i++) { // holidays can chain for weeks
            $day = $at->copy()->startOfDay()->addDays($i);
            $open = $day->copy()->setTimeFromTimeString((string) ($this->work_start ?: '09:00'));
            if ($this->isWorkingDay($day) && $open->gt($at)) {
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

        $note = __('chat.sales_off_hours', ['hours' => $this->hoursText($locale), 'when' => $days > 7 ? $open->format('d.m') : $when, 'time' => $open->format('H:i')], $locale);
        $holiday = $this->holidayOn($now);

        return $holiday === null ? $note
            : __($holiday !== '' ? 'chat.sales_holiday_named' : 'chat.sales_holiday', ['holiday' => $holiday], $locale).' '.$note;
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
            ."\n\n# WORKING HOURS (Tashkent time)\n{$days} {$this->work_start}–{$this->work_end}. In Business chats during working hours a manager gets {$this->wait_minutes} minutes to answer first; outside working hours ".($this->offHoursMode() === 'ai'
                ? 'you answer at once and fully: answer questions and take the order or request as usual. Managers are not at work then: whatever a manager must confirm (a special price or discount, a delivery date, reserving stock, an invoice or payment, a call back) — collect it and say a manager will confirm it when the office opens (CONVERSATION CONTEXT says when); never say it is already done.'
                : 'customers get a fixed note with these hours; the managers answer them when the office opens.')
            .($this->upcomingHolidays() ? ' The office is closed on holidays: '.implode(', ', $this->upcomingHolidays()).'.' : '')
            .($this->largeOrderSum() > 0 ? "\n\n# LARGE ORDER\nWhen the products the customer wants, priced from PRODUCT CATALOG (price × quantity), add up to ".number_format($this->largeOrderSum(), 0, '', ' ')
                .' sum or more: do not sell further or discuss terms. Confirm the summary and the contacts, set confirmed true and say a manager will take the order personally; begin the summary with "Large order ≈ <total> sum".' : '')
            ."\n\n# KNOWLEDGE (use only this; never add facts)\n".SalesKnowledge::forPrompt();
    }
}
