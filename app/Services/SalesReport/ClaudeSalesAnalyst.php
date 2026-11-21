<?php

namespace App\Services\SalesReport;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use RuntimeException;

/**
 * Sends one week of sales conversations to Claude (official SDK, streamed: the input and the answer are long)
 * and returns the JSON text, which follows the schema given (structured outputs).
 * Tests replace this class in the container: the SDK's HTTP client is not caught by Http::fake.
 */
class ClaudeSalesAnalyst
{
    /**
     * @return array{text: string, model: string, input_tokens: int, output_tokens: int, cache_write_tokens: int, cache_read_tokens: int}
     *
     * @throws RuntimeException
     */
    public function analyze(string $apiKey, string $model, string $system, string $data, array $schema): array
    {
        $client = new Client(apiKey: $apiKey, requestOptions: ['timeout' => 600, 'maxRetries' => 2]);

        $text = '';
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0];
        $stop = null;
        try {
            $stream = $client->beta->messages->createStream(
                maxTokens: 64000,
                model: $model,
                system: [['type' => 'text', 'text' => $system]],
                messages: [['role' => 'user', 'content' => $data]],
                outputConfig: ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
            foreach ($stream as $event) {
                if ($event->type === 'message_start') {
                    $model = $event->message->model;
                    $usage['input_tokens'] = (int) $event->message->usage->inputTokens;
                    $usage['cache_write_tokens'] = (int) $event->message->usage->cacheCreationInputTokens;
                    $usage['cache_read_tokens'] = (int) $event->message->usage->cacheReadInputTokens;
                } elseif ($event->type === 'content_block_delta' && $event->delta->type === 'text_delta') {
                    $text .= $event->delta->text;
                } elseif ($event->type === 'message_delta') {
                    $stop = $event->delta->stopReason ?? $stop;
                    $usage['output_tokens'] = (int) $event->usage->outputTokens;
                    if ($event->usage->inputTokens !== null) {
                        $usage['input_tokens'] = (int) $event->usage->inputTokens;
                    }
                }
            }
        } catch (APIStatusException $e) {
            throw new RuntimeException("Claude API error {$e->status}: ".$this->clean($e->getMessage(), $apiKey), 0, $e);
        } catch (APIConnectionException $e) {
            throw new RuntimeException('Claude API not reachable: '.$this->clean($e->getMessage(), $apiKey), 0, $e);
        }

        if ($stop === 'refusal') {
            throw new RuntimeException('Claude declined to analyse these conversations.');
        }
        if ($stop === 'max_tokens') {
            throw new RuntimeException('Claude\'s answer was cut off (too many conversations for one report).');
        }
        if (trim($text) === '') {
            throw new RuntimeException('Claude returned no text (stop reason: '.($stop ?? 'none').').');
        }

        return ['text' => $text, 'model' => $model] + $usage;
    }

    private function clean(string $message, string $apiKey): string
    {
        return mb_strimwidth(str_replace($apiKey, '***', $message), 0, 400, '…');
    }
}
