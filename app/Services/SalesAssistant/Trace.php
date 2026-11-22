<?php

namespace App\Services\SalesAssistant;

use App\Models\AiTrace;
use App\Services\Invoices\ClaudePricing;

/**
 * Collects the steps of one AI turn while it runs and saves them as an AiTrace. Each step: where it happened
 * (customer | laravel | claude | telegram), a title, the time since the start and the data sent or received there.
 */
class Trace
{
    public string $status = 'silent';

    public array $attributes = [];

    private array $steps = [];

    private float $start;

    public function __construct(public string $source = 'live', public string $kind = 'reply')
    {
        $this->start = microtime(true);
    }

    public function step(string $where, string $title, array $data = []): void
    {
        $this->steps[] = ['where' => $where, 'title' => $title, 'ms' => $this->elapsed(), 'data' => $data];
    }

    public function elapsed(): int
    {
        return (int) round((microtime(true) - $this->start) * 1000);
    }

    /** @param  array{model?: string, input_tokens?: int, output_tokens?: int}  $reply */
    public function usage(array $reply): void
    {
        $this->attributes += [
            'model' => mb_substr((string) ($reply['model'] ?? ''), 0, 60) ?: null,
            'input_tokens' => (int) ($reply['input_tokens'] ?? 0),
            'output_tokens' => (int) ($reply['output_tokens'] ?? 0),
            'cost_usd' => ClaudePricing::cost($reply['model'] ?? null, (int) ($reply['input_tokens'] ?? 0), (int) ($reply['output_tokens'] ?? 0)),
        ];
    }

    public function save(array $attributes = []): ?AiTrace
    {
        try {
            return AiTrace::create($attributes + $this->attributes + [
                'source' => $this->source, 'kind' => $this->kind, 'status' => $this->status,
                'duration_ms' => $this->elapsed(), 'steps' => $this->steps,
            ]);
        } catch (\Throwable $e) {
            report($e); // a trace never breaks the conversation

            return null;
        }
    }
}
