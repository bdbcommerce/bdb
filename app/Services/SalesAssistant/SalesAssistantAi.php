<?php

namespace App\Services\SalesAssistant;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use RuntimeException;

/**
 * One turn of the sales assistant: Claude (official SDK) gets the system prompt, the conversation and any photos,
 * and answers with JSON that follows SalesAssistant::schema() (structured outputs).
 * Tests replace this class in the container: the SDK's HTTP client is not caught by Http::fake.
 */
class SalesAssistantAi
{
    /**
     * @param  list<array{type: string, data: string}>  $images  base64 pictures (media type + data)
     * @return array{text: string, model: string, input_tokens: int, output_tokens: int}
     *
     * @throws RuntimeException
     */
    public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
    {
        $content = [['type' => 'text', 'text' => $data]];
        foreach ($images as $image) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $image['type'], 'data' => $image['data']]];
        }

        try {
            $message = (new Client(apiKey: $apiKey, requestOptions: ['timeout' => 60, 'maxRetries' => 1]))->beta->messages->create(
                maxTokens: 4000,
                model: $model,
                system: [['type' => 'text', 'text' => $system]],
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
        } catch (APIStatusException $e) {
            throw new RuntimeException("Claude API error {$e->status}: ".$this->clean($e->getMessage(), $apiKey), 0, $e);
        } catch (APIConnectionException $e) {
            throw new RuntimeException('Claude API not reachable: '.$this->clean($e->getMessage(), $apiKey), 0, $e);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude declined to answer this conversation.');
        }
        if (trim($text) === '') {
            throw new RuntimeException('Claude returned no text (stop reason: '.($message->stopReason ?? 'none').').');
        }

        return ['text' => $text, 'model' => (string) $message->model,
            'input_tokens' => (int) $message->usage->inputTokens, 'output_tokens' => (int) $message->usage->outputTokens];
    }

    private function clean(string $message, string $apiKey): string
    {
        return mb_strimwidth(str_replace($apiKey, '***', $message), 0, 400, '…');
    }
}
