<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Invoice parser: documents named "*.pdf" with a non-PDF declared type. */
class InvoicePdfNameTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    public array $reads = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true]);
        InvoiceParserSetting::current()->update([
            'enabled' => true, 'api_key' => 'sk-ant-test-key', 'source_chat_id' => self::SOURCE, 'target_chat_id' => '-1002222222222',
        ]);
        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoicePdfNameTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads[] = $mime;

                return ['text' => json_encode(['status' => 'not_invoice']), 'model' => 'claude-opus-5-5', 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    private function fakeBody(string $body): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/file_1.pdf']]),
            'api.telegram.org/file/*' => Http::response($body, 200, ['Content-Type' => 'application/octet-stream']),
            '*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function doc(int $id, mixed $document): array
    {
        return ['update_id' => $id, 'message' => [
            'message_id' => $id, 'date' => time(), 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'],
            'from' => ['id' => 5, 'first_name' => 'Ali'], 'document' => $document,
        ]];
    }

    private function receive(array $update): void
    {
        app(TelegramBot::class)->handle($update);
        $this->artisan('invoices:process')->assertSuccessful();
    }

    public function test_name_edge_cases(): void
    {
        $this->fakeBody("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        $d = fn (int $id, $mime, $name) => $this->doc($id, ['file_id' => "d{$id}", 'mime_type' => $mime, 'file_name' => $name]);

        $this->receive($d(1, 'application/octet-stream', 'Счет.pdf  '));    // trailing spaces
        $this->receive($d(2, 'application/octet-stream', 'Счет.PdF'));
        $this->receive($d(3, null, 'Счет.pdf'));                           // mime missing
        $this->receive($d(4, 'application/octet-stream', 'Счет.pdf.exe'));
        $this->receive($d(5, 'application/octet-stream', null));
        $this->receive($d(6, 'application/octet-stream', 'Счет.pdfx'));
        $this->receive($d(7, 'application/octet-stream', 'Счет pdf'));
        $this->receive($d(8, 'application/x-msdownload', "Счет.pdf\n"));
        $this->receive($d(9, 'application/octet-stream', "Счет\xff.pdf"));  // invalid UTF-8
        $this->receive($this->doc(10, 'Счет.pdf'));                          // document is a string
        $this->receive($this->doc(11, ['file_id' => 'd11', 'mime_type' => 'application/octet-stream', 'file_name' => ['Счет.pdf']]));
        $this->receive($d(12, 'application/octet-stream', 'Счет.pdf'."\u{00A0}")); // NBSP

        $this->assertSame(['d1', 'd2', 'd3', 'd8', 'd12'], InvoiceMessage::orderBy('id')->pluck('file_id')->all());
        $this->assertSame(['application/pdf'], array_values(array_unique($this->reads)));
    }

    public function test_pdf_named_non_pdf_body_is_refused_after_download(): void
    {
        $this->fakeBody("PK\x03\x04 this is a zip, not a pdf");
        $this->receive($this->doc(1, ['file_id' => 'z1', 'mime_type' => 'application/octet-stream', 'file_name' => 'Счет 1.pdf']));
        $this->fakeBody("MZ\x90\x00 an exe");
        $this->receive($this->doc(2, ['file_id' => 'z2', 'mime_type' => 'application/octet-stream', 'file_name' => 'Счет 2.pdf']));
        $this->fakeBody('plain text body');
        $this->receive($this->doc(3, ['file_id' => 'z3', 'mime_type' => '', 'file_name' => 'Счет 3.pdf']));

        $rows = InvoiceMessage::orderBy('id')->get(['file_id', 'mime', 'status'])->toArray();
        $this->assertSame([], $this->reads, 'a non-PDF body must never reach Claude');
        $this->assertSame(['skipped', 'skipped', 'skipped'], array_column($rows, 'status'));
    }
}
