<?php

namespace App\Services\Invoices;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use RuntimeException;

/**
 * Sends one invoice photo (or PDF) to the Claude API and returns the text of the reply.
 * The prompt asks for JSON only; InvoiceParser decodes it.
 */
class ClaudeInvoiceReader
{
    /** Image types Claude reads; PDFs go as a document block. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Models that support the server-side refusal fallback (Claude Haiku 5.5 does not). */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    /**
     * @return array{text: string, model: string, input_tokens: int, output_tokens: int}
     *
     * @throws InvoiceReadException with `retryable` set for rate limits, overload, timeouts and network errors
     */
    public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
    {
        $source = ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($bytes)];
        $file = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => $source]
            : ['type' => 'image', 'source' => $source];

        $client = new Client(apiKey: $apiKey, requestOptions: ['timeout' => 120, 'maxRetries' => 2]);

        try {
            $params = [
                'model' => $model,
                'maxTokens' => 16000,
                // The prompt is the same for every photo: cache it.
                'system' => [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                'messages' => [['role' => 'user', 'content' => [$file, ['type' => 'text', 'text' => 'Read this document.']]]],
                // Accuracy matters more than speed for invoice numbers.
                'outputConfig' => ['effort' => 'high'],
            ];
            if (in_array($model, self::FALLBACK_MODELS, true)) {
                // If the model declines for policy reasons, the API retries on its default fallback model.
                $params += ['betas' => ['server-side-fallback-2026-07-01'], 'fallbacks' => 'default'];
            }

            $message = $client->beta->messages->create(...$params);
        } catch (APIStatusException $e) {
            $retryable = $e->status === 429 || $e->status === 408 || $e->status === 409 || $e->status >= 500;
            throw new InvoiceReadException("Claude API error {$e->status}: ".$this->clean($e->getMessage(), $apiKey), $retryable, $e);
        } catch (APIConnectionException $e) {
            throw new InvoiceReadException('Claude API not reachable: '.$this->clean($e->getMessage(), $apiKey), true, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new InvoiceReadException('Claude declined to read this image.', false);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        if (trim($text) === '') {
            throw new InvoiceReadException('Claude returned no text (stop reason: '.($message->stopReason ?? 'none').').', true);
        }

        return [
            'text' => $text,
            'model' => $message->model,
            'input_tokens' => $message->usage->inputTokens,
            'output_tokens' => $message->usage->outputTokens,
        ];
    }

    /** One tiny request to check the key and model (Settings → Test connection). */
    public function ping(string $apiKey, string $model): string
    {
        try {
            $message = (new Client(apiKey: $apiKey, requestOptions: ['timeout' => 30, 'maxRetries' => 0]))->messages->create(
                model: $model,
                maxTokens: 512,
                messages: [['role' => 'user', 'content' => 'Reply with the single word: OK']],
            );
        } catch (APIStatusException $e) {
            throw new RuntimeException("Error {$e->status}: ".$this->clean($e->getMessage(), $apiKey));
        } catch (APIConnectionException $e) {
            throw new RuntimeException('Not reachable: '.$this->clean($e->getMessage(), $apiKey));
        }

        return $message->model;
    }

    private function clean(string $text, string $apiKey): string
    {
        return mb_strimwidth(str_replace($apiKey, '***', $text), 0, 500, '…');
    }
}
