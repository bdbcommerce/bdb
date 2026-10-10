<?php

namespace App\Models;

use App\Models\Concerns\FlushesChatKnowledge;
use App\Services\ChatAssistant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-written Markdown document sent to the AI with every request.
 * kind "rules": how the assistant must behave; "info": extra facts it may use.
 */
class AiInstruction extends Model
{
    use FlushesChatKnowledge;

    public const KINDS = [
        'rules' => 'Rules (how the AI must behave)',
        'info' => 'Information (facts the AI may use)',
    ];

    public const CHANNELS = [
        'all' => 'Website and Telegram',
        'web' => 'Website chat only',
        'telegram' => 'Telegram bot only',
    ];

    /** Longest document; keeps every AI request a reasonable size. */
    public const MAX_LENGTH = 20000;

    /** Longest text of all active documents of one kind sent with a request (the rest is cut). */
    public const MAX_TOTAL = 40000;

    /** The single "main prompt" document: the AI's core instructions, editable by admins. */
    public const MAIN = 'prompt';

    protected $fillable = ['title', 'kind', 'channel', 'content', 'is_active', 'sort_order'];

    /** The main prompt row, created from the built-in default the first time it is needed. */
    public static function main(): self
    {
        return static::firstOrCreate(['kind' => self::MAIN], [
            'title' => 'Main prompt',
            'channel' => 'all',
            'content' => ChatAssistant::DEFAULT_PROMPT,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function isMain(): bool
    {
        return $this->kind === self::MAIN;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Active documents of a kind for a channel (web | telegram), in admin order. */
    public function scopeFor(Builder $query, string $kind, string $channel): void
    {
        $query->where('is_active', true)
            ->where('kind', $kind)
            ->whereIn('channel', ['all', $channel])
            ->orderBy('sort_order')->orderBy('id');
    }
}
