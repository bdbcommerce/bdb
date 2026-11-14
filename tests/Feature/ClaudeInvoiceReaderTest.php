<?php

namespace Tests\Feature;

use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\InvoiceReadException;
use GuzzleHttp\Psr7\Response;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/** The real ClaudeInvoiceReader against a fake PSR-18 transport (no network). */
class ClaudeInvoiceReaderTest extends TestCase
{
    /** @var list<RequestInterface> */
    public static array $requests = [];

    /** @var list<ResponseInterface> */
    public static array $responses = [];

    private static array $strategies = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$requests = self::$responses = [];
        // Safety net: even if the fake transport were not used, nothing could reach the real API.
        putenv('ANTHROPIC_BASE_URL=http://127.0.0.1:9');
        $_ENV['ANTHROPIC_BASE_URL'] = $_SERVER['ANTHROPIC_BASE_URL'] = 'http://127.0.0.1:9';
        self::$strategies = iterator_to_array(Psr18ClientDiscovery::getStrategies(), false);
        Psr18ClientDiscovery::prependStrategy(FakeAnthropicStrategy::class);
    }

    protected function tearDown(): void
    {
        Psr18ClientDiscovery::setStrategies(self::$strategies);
        putenv('ANTHROPIC_BASE_URL');
        unset($_ENV['ANTHROPIC_BASE_URL'], $_SERVER['ANTHROPIC_BASE_URL']);
        parent::tearDown();
    }

    private static function ok(array $over = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($over + [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => '{"status":"skipped","reason":"x"}']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 11, 'output_tokens' => 7],
        ]));
    }

    public function test_request_shape_for_opus_with_fallback(): void
    {
        self::$responses = [self::ok()];
        $r = (new ClaudeInvoiceReader)->read('sk-ant-secret', 'claude-opus-5-5', 'SYSTEM', 'BYTES', 'image/png');

        $this->assertSame(['text' => '{"status":"skipped","reason":"x"}', 'model' => 'claude-opus-5-5', 'input_tokens' => 11, 'output_tokens' => 7], $r);
        $this->assertCount(1, self::$requests);
        $req = self::$requests[0];
        $this->assertSame('/v1/messages', $req->getUri()->getPath());
        $this->assertSame('127.0.0.1', $req->getUri()->getHost());
        $this->assertSame('Bearer sk-ant-secret', $req->getHeaderLine('Authorization'), 'SDK 1.8 sends the API key as a Bearer token');
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $req->getHeaderLine('anthropic-beta'));
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['effort' => 'high'], $body['output_config']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('BYTES')], $body['messages'][0]['content'][0]['source']);
        $this->assertSame('image', $body['messages'][0]['content'][0]['type']);
    }

    public function test_haiku_has_no_fallback_and_pdf_is_a_document(): void
    {
        self::$responses = [self::ok()];
        (new ClaudeInvoiceReader)->read('k', 'claude-haiku-5-5', 'S', '%PDF', 'application/pdf');
        $body = json_decode((string) self::$requests[0]->getBody(), true);
        $this->assertArrayNotHasKey('fallbacks', $body);
        $this->assertStringNotContainsString('server-side-fallback', self::$requests[0]->getHeaderLine('anthropic-beta'));
        $this->assertSame('document', $body['messages'][0]['content'][0]['type']);
    }

    public function test_errors_are_classified_and_key_hidden(): void
    {
        self::$responses = [new Response(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad image for key sk-ant-secret']]))];
        try {
            (new ClaudeInvoiceReader)->read('sk-ant-secret', 'claude-opus-5-5', 'S', 'B', 'image/png');
            $this->fail('no exception');
        } catch (InvoiceReadException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringNotContainsString('sk-ant-secret', $e->getMessage());
        }

        self::$requests = [];
        self::$responses = array_map(fn () => new Response(429, ['Content-Type' => 'application/json', 'retry-after' => '0'], json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']])), range(1, 3));
        try {
            (new ClaudeInvoiceReader)->read('k', 'claude-opus-5-5', 'S', 'B', 'image/png');
            $this->fail('no exception');
        } catch (InvoiceReadException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_refusal_and_empty_text(): void
    {
        self::$responses = [self::ok(['stop_reason' => 'refusal', 'content' => []])];
        try {
            (new ClaudeInvoiceReader)->read('k', 'claude-opus-5-5', 'S', 'B', 'image/png');
            $this->fail('no exception');
        } catch (InvoiceReadException $e) {
            $this->assertFalse($e->retryable);
        }

        self::$responses = [self::ok(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '  ']]])];
        try {
            (new ClaudeInvoiceReader)->read('k', 'claude-opus-5-5', 'S', 'B', 'image/png');
            $this->fail('no exception');
        } catch (InvoiceReadException $e) {
            $this->assertTrue($e->retryable);
            $this->assertStringContainsString('max_tokens', $e->getMessage());
        }
    }

    public function test_ping(): void
    {
        self::$responses = [self::ok(['model' => 'claude-sonnet-5-5'])];
        $this->assertSame('claude-sonnet-5-5', (new ClaudeInvoiceReader)->ping('k', 'claude-sonnet-5-5'));
        $this->assertStringEndsWith('/v1/messages', (string) self::$requests[0]->getUri()->withQuery(''));
    }
}

class FakeAnthropicStrategy implements DiscoveryStrategy
{
    public static function getCandidates($type)
    {
        if ($type !== ClientInterface::class) {
            return [];
        }

        return [['class' => fn () => new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ClaudeInvoiceReaderTest::$requests[] = $request;

                return array_shift(ClaudeInvoiceReaderTest::$responses) ?? throw new \RuntimeException('Unexpected extra request to '.$request->getUri());
            }
        }, 'condition' => true]];
    }
}
