<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * An AI model the assistant can use. Models for a task are tried by priority (lowest first);
 * one that fails is paused for a while and the next one answers.
 */
class AiModel extends Model
{
    public const PROVIDERS = [
        'gemini' => 'Google Gemini',
        'openai' => 'OpenAI-compatible API (Groq, OpenRouter, Mistral, …)',
    ];

    public const TASKS = [
        'chat' => 'Website chat',
        'telegram' => 'Telegram bot',
        'admin' => 'Admin tools (AI drafts, matching questions, reply suggestions)',
    ];

    private const GOOGLE_STEPS = [
        'Open https://aistudio.google.com/apikey and sign in with a Google account.',
        'Click "Create API key" (pick or create a Google Cloud project when asked).',
        'Copy the key (it starts with AIza) and paste it into the API key field here, tick Active and save.',
        'Optional: to lift the small free daily limit, open Billing in Google AI Studio and enable it for that project.',
    ];

    private const GROQ_STEPS = [
        'Open https://console.groq.com and sign up (Google account or e-mail).',
        'Go to API Keys (https://console.groq.com/keys) and click "Create API Key".',
        'Name it (e.g. "BDB site"), copy the key (it starts with gsk_; it is shown only once), paste it here, tick Active and save.',
    ];

    /**
     * Ready-made models with a free tier (as of 2026; free limits and model names change, check the provider).
     * Each needs its own free API key: "steps" says how to get it. Seeded switched off (AiModelSeeder).
     */
    public const PRESETS = [
        'gemini-flash' => [
            'name' => 'Google Gemini Flash', 'provider' => 'gemini', 'model' => 'gemini-flash-latest', 'base_url' => null, 'priority' => 10,
            'key_url' => 'https://aistudio.google.com/apikey', 'steps' => self::GOOGLE_STEPS,
            'note' => 'Best quality for Uzbek and Russian. Free tier has a small daily limit; enable billing for real traffic.',
        ],
        'gemini-flash-lite' => [
            'name' => 'Google Gemini Flash-Lite', 'provider' => 'gemini', 'model' => 'gemini-flash-lite-latest', 'base_url' => null, 'priority' => 20,
            'key_url' => 'https://aistudio.google.com/apikey', 'steps' => self::GOOGLE_STEPS,
            'note' => 'Faster and cheaper than Flash, with a larger free daily limit. The same Google key works for both.',
        ],
        'groq-llama-70b' => [
            'name' => 'Groq Llama 3.3 70B', 'provider' => 'openai', 'model' => 'llama-3.3-70b-versatile', 'base_url' => 'https://api.groq.com/openai/v1', 'priority' => 30,
            'key_url' => 'https://console.groq.com/keys', 'steps' => self::GROQ_STEPS,
            'note' => 'Free tier, very fast. Good in Russian and English, weaker in Uzbek.',
        ],
        'openrouter-free' => [
            'name' => 'OpenRouter Llama 3.3 70B (free)', 'provider' => 'openai', 'model' => 'meta-llama/llama-3.3-70b-instruct:free', 'base_url' => 'https://openrouter.ai/api/v1', 'priority' => 40,
            'key_url' => 'https://openrouter.ai/keys',
            'steps' => [
                'Open https://openrouter.ai and sign up.',
                'Go to https://openrouter.ai/keys and click "Create Key".',
                'Copy the key (it starts with sk-or-), paste it here, tick Active and save.',
                'Free models end in ":free"; the current list is at https://openrouter.ai/models?max_price=0 if this model name stops working.',
            ],
            'note' => 'Free ":free" models with daily limits. Any other OpenRouter model name works too.',
        ],
        'mistral-small' => [
            'name' => 'Mistral Small', 'provider' => 'openai', 'model' => 'mistral-small-latest', 'base_url' => 'https://api.mistral.ai/v1', 'priority' => 50,
            'key_url' => 'https://console.mistral.ai/api-keys',
            'steps' => [
                'Open https://console.mistral.ai and sign up.',
                'Choose the free "Experiment" plan (phone verification may be asked).',
                'Go to API Keys, click "Create new key", copy it, paste it here, tick Active and save.',
            ],
            'note' => 'Free "Experiment" plan with rate limits.',
        ],
        'groq-llama-8b' => [
            'name' => 'Groq Llama 3.1 8B Instant', 'provider' => 'openai', 'model' => 'llama-3.1-8b-instant', 'base_url' => 'https://api.groq.com/openai/v1', 'priority' => 60,
            'key_url' => 'https://console.groq.com/keys', 'steps' => self::GROQ_STEPS,
            'note' => 'Free tier with high limits. Small model: good as the last backup.',
        ],
    ];

    /** The preset this model was made from (same provider and model name), for its key instructions. */
    public function preset(): ?array
    {
        foreach (self::PRESETS as $preset) {
            if ($preset['provider'] === $this->provider && $preset['model'] === $this->model) {
                return $preset;
            }
        }

        return null;
    }

    protected $fillable = [
        'name', 'provider', 'model', 'base_url', 'api_key', 'tasks', 'priority', 'is_active',
        'last_success_at', 'last_error_at', 'last_error', 'cooldown_until',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'tasks' => 'array',
            'is_active' => 'boolean',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'cooldown_until' => 'datetime',
        ];
    }

    /**
     * Models to try for a task: active ones by priority, paused ones last (still better than no answer).
     * With none configured, a model built from the .env Gemini settings.
     *
     * @return Collection<int, self>
     */
    public static function forTask(string $task): Collection
    {
        $models = static::where('is_active', true)->orderBy('priority')->orderBy('id')->get()
            ->filter(fn (self $m) => in_array($task, (array) $m->tasks, true));

        if ($models->isEmpty()) {
            return new Collection([self::fromEnv()]);
        }

        return $models->sortBy(fn (self $m) => $m->isPaused() ? 1 : 0, SORT_REGULAR)->values();
    }

    /** The Gemini model from .env (not stored), used when no models are set up. */
    public static function fromEnv(): self
    {
        return new self([
            'name' => 'Gemini (.env)',
            'provider' => 'gemini',
            'model' => (string) config('services.gemini.model'),
            'tasks' => array_keys(self::TASKS),
            'is_active' => true,
        ]);
    }

    /** True when any model can be tried (configured models, or a Gemini key in .env). */
    public static function available(): bool
    {
        return static::where('is_active', true)->exists() || filled(config('services.gemini.key'));
    }

    /** The key to send: the model's own, or GEMINI_API_KEY for Gemini models without one. */
    public function key(): ?string
    {
        return filled($this->api_key) ? $this->api_key : ($this->provider === 'gemini' ? config('services.gemini.key') : null);
    }

    public function isPaused(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    public function markSuccess(): void
    {
        if ($this->exists) {
            $this->forceFill(['last_success_at' => now(), 'cooldown_until' => null])->saveQuietly();
        }
    }

    /** Remember the error and pause the model: long for quota/config errors, short for hiccups. */
    public function markFailure(string $error, ?int $status): void
    {
        if (! $this->exists) {
            return;
        }

        $minutes = match (true) {
            $status === 429 => 15,
            in_array($status, [400, 401, 403, 404], true) => 60,
            default => 2,
        };

        $this->forceFill([
            'last_error' => mb_substr($error, 0, 1000),
            'last_error_at' => now(),
            'cooldown_until' => now()->addMinutes($minutes),
        ])->saveQuietly();
    }
}
