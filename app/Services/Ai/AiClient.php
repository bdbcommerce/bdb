<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\AiErrorAlerts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One structured (JSON) request to one AI model, for Gemini or any OpenAI-compatible API.
 * Never throws: returns the decoded JSON, or the reason it failed.
 */
class AiClient
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * @param  array<int, array{role: string, content: string}>  $contents  roles: user|assistant
     * @param  array  $schema  Gemini-style response schema (type OBJECT/STRING/…)
     * @return array{data: array|null, reason: string|null, details: string|null, status: int|null}
     */
    public function call(AiModel $model, string $system, array $contents, array $schema, float $temperature, int $maxTokens): array
    {
        $isEnv = ! $model->exists;
        $label = $model->provider === 'gemini' ? 'Gemini' : $model->name;

        if (blank($model->key())) {
            return $this->failure($isEnv ? 'GEMINI_API_KEY is not set in .env' : "No API key for {$model->name}");
        }
        if ($model->provider === 'openai' && blank($model->base_url)) {
            return $this->failure("No API address (base URL) for {$model->name}");
        }

        try {
            $response = $model->provider === 'gemini'
                ? $this->gemini($model, $system, $contents, $schema, $temperature, $maxTokens)
                : $this->openai($model, $system, $contents, $schema, $temperature, $maxTokens);
        } catch (ConnectionException $e) {
            Log::warning("{$label} request failed", ['model' => $model->model, 'error' => AiErrorAlerts::withoutSecrets($e->getMessage(), $model->key())]);

            return $this->failure("{$label} did not respond (timeout or network problem)", $e->getMessage());
        }

        if ($response->failed()) {
            Log::warning("{$label} request failed", ['model' => $model->model, 'status' => $response->status(), 'body' => AiErrorAlerts::withoutSecrets($response->body(), $model->key())]);

            // e.g. 400 key not valid / model not found, 401, 403 location not supported, 429 quota.
            $errorStatus = collect([$response->json('error.status'), $response->json('error.type'), $response->json('error.code')])->first(fn ($v) => is_string($v) && $v !== '');

            return $this->failure(
                "{$label} returned HTTP ".$response->status().(is_string($errorStatus) ? ' '.$errorStatus : ''),
                is_string($message = $response->json('error.message')) ? $message : mb_substr($response->body(), 0, 600),
                $response->status(),
            );
        }

        [$text, $why] = $model->provider === 'gemini'
            ? [$response->json('candidates.0.content.parts.0.text'), $response->json('promptFeedback.blockReason') ?? $response->json('candidates.0.finishReason')]
            : [$response->json('choices.0.message.content'), $response->json('choices.0.finish_reason')];

        $data = is_string($text) ? self::decodeJson($text) : null;
        if (! is_array($data)) {
            // Usually a blocked or cut-off answer: report why the model stopped.
            return $this->failure("{$label} gave no usable answer", 'Reason: '.(is_string($why) ? $why : (json_encode($why) ?: 'no text')));
        }

        return ['data' => $data, 'reason' => null, 'details' => null, 'status' => $response->status()];
    }

    private function gemini(AiModel $model, string $system, array $contents, array $schema, float $temperature, int $maxTokens): Response
    {
        $config = [
            'temperature' => $temperature,
            'maxOutputTokens' => $maxTokens,
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ];
        if (str_contains($model->model, 'flash')) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        return Http::timeout(25)
            ->withHeaders(['x-goog-api-key' => $model->key()])
            ->post(sprintf(self::GEMINI_ENDPOINT, $model->model), [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => array_map(fn (array $m) => [
                    'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ], $contents),
                'generationConfig' => $config,
            ]);
    }

    /** OpenAI chat completions format: Groq, OpenRouter, Mistral, DeepSeek, Together, a local Ollama… */
    private function openai(AiModel $model, string $system, array $contents, array $schema, float $temperature, int $maxTokens): Response
    {
        $system .= "\n\nRespond with ONLY one JSON object (no markdown, no text around it) that matches this JSON schema:\n"
            .json_encode(self::jsonSchema($schema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Http::timeout(25)
            ->withToken($model->key())
            ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'BDB assistant']) // OpenRouter attribution
            ->post(rtrim($model->base_url, '/').'/chat/completions', [
                'model' => $model->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ...array_map(fn (array $m) => ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']], $contents),
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
            ]);
    }

    /** Gemini-style schema (OBJECT, STRING…) → standard JSON Schema (object, string…). */
    public static function jsonSchema(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            $out[$key] = match (true) {
                $key === 'type' && is_string($value) => strtolower($value),
                $key === 'propertyOrdering' => null,
                is_array($value) => self::jsonSchema($value),
                default => $value,
            };
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    /** JSON from a model reply, tolerating ```json fences or text around the object. */
    public static function decodeJson(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
        $data = json_decode($text, true);

        if (! is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }

        return is_array($data) ? $data : null;
    }

    private function failure(string $reason, ?string $details = null, ?int $status = null): array
    {
        return ['data' => null, 'reason' => $reason, 'details' => $details, 'status' => $status];
    }
}
