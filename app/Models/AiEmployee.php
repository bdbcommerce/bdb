<?php

namespace App\Models;

use App\Support\AiEmployeeTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AI employee: the name customers see, a role, a temperament (how they talk) and versioned role prompts.
 * Their prompt = platform rules → this card (who, how they talk, handover) → the published role prompt; for the
 * sales consultant the engine then adds the sales playbook, working hours and knowledge (SalesAssistantSetting).
 * Routing: a chat starts with the sales consultant; an employee hands the customer to a colleague (hand_to in the
 * answer) and the colleague answers at once. Live employees send their replies; shadow employees only draft them
 * for a manager to send, edit or discard (AiShadowDraft).
 */
class AiEmployee extends Model
{
    public const KINDS = [
        'sales' => 'Sales consultant (new customers)',
        'support' => 'Technical support',
        'service' => 'Service desk (repair, warranty, parts)',
        'account' => 'Account manager (after the sale, repeat orders)',
        'head' => 'Head of sales (internal reviews)',
    ];

    /** Roles that can talk to customers (and be handed a chat). */
    public const RUNNABLE_KINDS = ['sales', 'support', 'service'];

    public const STATUSES = ['draft' => 'Draft', 'shadow' => 'Shadow', 'live' => 'Live'];

    /** Statuses in which the employee takes part in customer chats. */
    public const ACTIVE = ['live', 'shadow'];

    public const CHANNELS = ['bot' => 'Bot chat', 'business' => 'Premium account'];

    /** Temperament scales (1–5): [low end, high end, words per level for the prompt]. */
    public const SCALES = [
        'formality' => ['Friendly', 'Formal', [1 => 'very friendly and informal', 2 => 'friendly and warm', 3 => 'neutral and polite', 4 => 'polite and businesslike', 5 => 'very formal']],
        'length' => ['Very short', 'Detailed', [1 => 'very short — one or two sentences', 2 => 'short — two or three sentences', 3 => 'medium', 4 => 'fairly detailed', 5 => 'detailed and thorough']],
        'initiative' => ['Waits for the customer', 'Leads the conversation', [1 => 'let the customer lead; answer what is asked', 2 => 'mostly follow the customer', 3 => 'balanced', 4 => 'gently lead with questions', 5 => 'lead the conversation actively']],
        'emoji' => ['Never', 'Often', [1 => 'never use emoji', 2 => 'rarely — at most one emoji', 3 => 'sometimes', 4 => 'often', 5 => 'freely']],
    ];

    protected $fillable = ['name', 'role', 'kind', 'status', 'audience', 'channels', 'scales', 'traits', 'manner', 'say', 'avoid', 'handover', 'sort'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'scales' => 'array', 'traits' => 'array'];
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(AiEmployeePrompt::class)->orderByDesc('version');
    }

    public function publishedPrompt(): ?AiEmployeePrompt
    {
        return $this->prompts()->where('status', 'published')->first();
    }

    public function draftPrompt(): ?AiEmployeePrompt
    {
        return $this->prompts()->where('status', 'draft')->first();
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function isShadow(): bool
    {
        return $this->status === 'shadow';
    }

    /** The live employee of this role who answers customers (with a published prompt), if any. */
    public static function live(string $kind): ?self
    {
        return static::where('kind', $kind)->where('status', 'live')->orderBy('sort')->orderBy('id')->get()
            ->first(fn (self $e) => $e->publishedPrompt() !== null);
    }

    /** The employee of this role taking part in customer chats — live or shadow, with a published prompt. */
    public static function active(string $kind): ?self
    {
        return static::where('kind', $kind)->whereIn('status', self::ACTIVE)->orderBy('sort')->orderBy('id')->get()
            ->first(fn (self $e) => $e->publishedPrompt() !== null);
    }

    /** Colleagues this employee may hand a customer to: the other active roles. */
    public function colleagues(): array
    {
        return collect(self::RUNNABLE_KINDS)->reject(fn ($k) => $k === $this->kind)
            ->map(fn ($k) => self::active($k))->filter()->values()->all();
    }

    public static function platformRules(): string
    {
        $rules = SalesAssistantSetting::current()->platform_rules;

        return filled($rules) ? (string) $rules : AiEmployeeTemplates::PLATFORM_RULES;
    }

    /** Level 1–5 of a temperament scale. */
    public function scale(string $key): int
    {
        return max(1, min(5, (int) (($this->scales ?? [])[$key] ?? 3)));
    }

    /**
     * The employee's part of the system prompt: platform rules, the card and a role prompt
     * ($prompt: a draft to preview; default the published one).
     */
    public function promptLayers(?string $prompt = null): string
    {
        $prompt ??= $this->publishedPrompt()?->prompt ?? '';
        $rules = strtr(self::platformRules(), ['{name}' => $this->name, '{role}' => mb_strtolower($this->role)]);
        $tone = collect(self::SCALES)->map(fn ($s, $key) => '- '.ucfirst($key).': '.$s[2][$this->scale($key)])->implode("\n");
        $card = array_filter([
            "# YOUR CARD\nName: {$this->name} (introduce yourself by this name). Role: {$this->role}.".($this->audience ? " You talk to: {$this->audience}." : ''),
            "# HOW YOU TALK\n".($this->traits ? 'Character: '.implode(', ', $this->traits).".\n" : '').$tone
                .($this->manner ? "\n".$this->manner : '')
                .($this->say ? "\nSay it like this (tone example): \"{$this->say}\"" : '')
                .($this->avoid ? "\nNever like this: \"{$this->avoid}\"" : ''),
            $this->handover ? "# WHEN TO HAND OVER\n{$this->handover}" : null,
            ($colleagues = $this->colleagues()) ? "# YOUR COLLEAGUES (hand the customer over with hand_to when the need is theirs)\n"
                .collect($colleagues)->map(fn (self $c) => "- {$c->name}, ".mb_strtolower($c->role)." — hand_to: {$c->kind}".($c->audience ? ". Takes: {$c->audience}" : ''))->implode("\n") : null,
            trim($prompt) !== '' ? "# YOUR ROLE\n".trim($prompt) : null,
        ]);

        return trim($rules)."\n\n".implode("\n\n", $card);
    }
}
