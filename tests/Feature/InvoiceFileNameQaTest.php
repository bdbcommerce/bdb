<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\InvoiceParser;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: invoice files are captured only when named "Счет…"; photos and older captured documents unaffected. */
class InvoiceFileNameQaTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true]);
        InvoiceParserSetting::current()->update([
            'enabled' => true, 'api_key' => 'sk-ant-test-key', 'source_chat_id' => self::SOURCE, 'target_chat_id' => '-1002222222222',
        ]);
        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceFileNameQaTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads++;

                return ['text' => '{"status": "skipped", "reason": "test"}', 'model' => 'claude-opus-5-5', 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/f.png']]),
            'api.telegram.org/file/*' => Http::response(base64_decode(self::PNG)),
            '*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function update(int $id, array $extra): array
    {
        return ['update_id' => $id, 'message' => $extra + [
            'message_id' => $id, 'date' => time(), 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'],
            'from' => ['id' => 5, 'first_name' => 'Ali'],
        ]];
    }

    private function doc(int $id, mixed $name, string $mime = 'application/pdf'): array
    {
        return $this->update($id, ['document' => ['file_id' => "doc{$id}", 'mime_type' => $mime, 'file_name' => $name]]);
    }

    public function test_unicode_and_odd_names(): void
    {
        $this->assertTrue(InvoiceParser::isInvoiceFileName('СЧЁТ.pdf'), 'uppercase Ё');
        $this->assertTrue(InvoiceParser::isInvoiceFileName("\tСчет.pdf"));
        $this->assertTrue(InvoiceParser::isInvoiceFileName('Счет'.str_repeat('я', 100000).'.pdf'), 'very long name');
        $this->assertFalse(InvoiceParser::isInvoiceFileName("\xD0Счет.pdf"), 'invalid UTF-8 does not throw');
        $this->assertFalse(InvoiceParser::isInvoiceFileName(true));
        $this->assertFalse(InvoiceParser::isInvoiceFileName(12.5));
    }

    /** macOS / some phone apps send file names decomposed (NFD): "ё" = "е" + U+0308. */
    public function test_decomposed_yo_is_an_invoice_name(): void
    {
        $this->assertTrue(InvoiceParser::isInvoiceFileName("Сче\u{0308}т №5.pdf"), 'NFD ё');
        $this->assertTrue(InvoiceParser::isInvoiceFileName("СЧЕ\u{0308}Т.pdf"), 'NFD Ё');
    }

    public function test_file_not_named_so_is_ignored_silently(): void
    {
        $bot = app(TelegramBot::class);
        foreach ([$this->doc(1, 'Договор.pdf'), $this->doc(2, null), $this->doc(3, ['Счет.pdf']), $this->doc(4, 'scan.png', 'image/png'),
            $this->update(5, ['document' => ['file_id' => 'x', 'mime_type' => 'application/pdf']])] as $u) {
            $bot->handle($u);
        }
        $this->artisan('invoices:process')->assertSuccessful();

        $this->assertSame(0, InvoiceMessage::count());
        $this->assertSame(0, ChatConversation::count(), 'not recorded as a group chat');
        Http::assertNothingSent();
        $this->assertSame(0, $this->reads);
    }

    public function test_photos_unaffected_and_from_ids_apply_to_files(): void
    {
        $bot = app(TelegramBot::class);
        $bot->handle($this->update(10, ['photo' => [['file_id' => 'p1', 'width' => 10]], 'caption' => 'Договор']));
        $this->assertSame('p1', InvoiceMessage::sole()->file_id, 'photos are read whatever the caption');

        InvoiceParserSetting::current()->update(['source_from_ids' => '777']);
        $bot->handle($this->doc(11, 'Счет 1.pdf'));
        $this->assertSame(1, InvoiceMessage::count(), 'file from a sender outside From IDs ignored');

        $bot->handle($this->update(12, ['from' => ['id' => 777], 'document' => ['file_id' => 'd12', 'mime_type' => 'application/pdf', 'file_name' => 'Счет 1.pdf']]));
        $this->assertSame(2, InvoiceMessage::count());
    }

    public function test_older_documents_without_a_name_are_still_retried_and_read_again(): void
    {
        // Captured before the change: a document row with no file name stored anywhere.
        $old = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 99, 'file_id' => 'old-doc', 'mime' => 'application/pdf']);
        $failed = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 98, 'file_id' => 'old-doc2', 'mime' => 'image/png', 'status' => 'failed', 'attempts' => 1, 'error' => 'x']);
        $failed->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $this->artisan('invoices:process')->assertSuccessful();
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertSame('skipped', $old->fresh()->status);
        $this->assertSame('skipped', $failed->fresh()->status, 'scheduler retry');

        $admin = User::factory()->create(['is_admin' => true]);
        $before = $this->reads;
        $this->actingAs($admin)->post(route('admin.invoice-messages.retry', $old))->assertRedirect();
        $this->assertSame($before + 1, $this->reads, '"Read again" reads it');
    }
}
