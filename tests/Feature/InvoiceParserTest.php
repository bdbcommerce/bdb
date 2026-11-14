<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\InvoiceParser;
use App\Services\Invoices\InvoiceReadException;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Admin → Invoice parser: photos in the source chat → Claude → summary in the destination chat. */
class InvoiceParserTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const TARGET = '-1002222222222';

    /** A 1×1 PNG (a real image, so the type check passes). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<array> calls the fake reader received */
    private array $reads = [];

    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true]);
        InvoiceParserSetting::current()->update([
            'enabled' => true, 'api_key' => 'sk-ant-test-key', 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET,
        ]);

        // Never call the real Claude API: the reader returns the queued replies.
        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceParserTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                return $this->test->nextReply(compact('apiKey', 'model', 'system', 'mime'));
            }
        });

        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']]),
            'api.telegram.org/file/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'application/octet-stream']),
            'api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    /** Telegram delivers an update; the scheduler (invoices:process) then reads what was stored. */
    private function receive(array $update): void
    {
        app(TelegramBot::class)->handle($update);
        $this->artisan('invoices:process')->assertSuccessful();
    }

    public function nextReply(array $call): array
    {
        $this->reads[] = $call;
        $reply = array_shift($this->replies);
        if ($reply instanceof InvoiceReadException) {
            throw $reply;
        }

        return ['text' => $reply, 'model' => 'claude-opus-5-5', 'input_tokens' => 1500, 'output_tokens' => 400];
    }

    private function invoiceJson(array $over = []): string
    {
        return json_encode($over + [
            'status' => 'accepted', 'invoice_number' => '125', 'invoice_date' => '2026-11-12',
            'supplier' => ['name' => 'OOO "Alpha" <b>', 'inn' => '301234567', 'vat_code' => null],
            'buyer' => ['name' => 'BDB Commerce', 'inn' => '305556677'],
            'items' => [
                ['name' => 'Zebra ZD230', 'unit' => 'dona', 'quantity' => 2, 'price' => 3500000, 'amount' => 7000000, 'vat_rate' => 12, 'vat_amount' => 840000, 'total_with_vat' => 7840000],
                ['name' => 'Labels 58×40', 'unit' => 'rulon', 'quantity' => 10, 'price' => 45000.5, 'amount' => 450005, 'vat_rate' => 12, 'vat_amount' => 54000.6, 'total_with_vat' => 504005.6],
            ],
            'total_amount' => 7450005, 'total_vat' => 894000.6, 'total_with_vat' => 8344005.6, 'amount_in_words' => '…',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function photo(int $id = 10, string $chat = self::SOURCE, array $extra = []): array
    {
        return ['update_id' => $id, 'message' => $extra + [
            'message_id' => $id, 'date' => time(), 'chat' => ['id' => (int) $chat, 'type' => 'supergroup'],
            'from' => ['id' => 5, 'first_name' => 'Ali', 'last_name' => 'Valiyev'],
            'photo' => [['file_id' => 'small', 'width' => 90, 'file_size' => 1000], ['file_id' => 'big', 'width' => 1280, 'file_size' => 90000]],
        ]];
    }

    public function test_photo_in_the_source_chat_is_read_and_the_summary_posted(): void
    {
        $this->replies = ["```json\n".$this->invoiceJson()."\n```"];

        $this->receive($this->photo());

        $invoice = InvoiceMessage::sole();
        $this->assertSame('sent', $invoice->status);
        $this->assertSame('big', $invoice->file_id, 'the largest photo size');
        $this->assertSame('Ali Valiyev', $invoice->sender);
        $this->assertSame('125', $invoice->result['invoice_number']);
        $this->assertSame(77, (int) $invoice->sent_message_id);
        $this->assertSame([1500, 400], [$invoice->input_tokens, $invoice->output_tokens]);
        $this->assertSame('image/png', $this->reads[0]['mime'], 'type from the file content');
        $this->assertSame('sk-ant-test-key', $this->reads[0]['apiKey']);
        $this->assertStringContainsString('PRINTED invoice', $this->reads[0]['system'], 'the built-in prompt');

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/sendMessage')) {
                return false;
            }
            $text = $r['text'];

            return $r['chat_id'] === self::TARGET && $r['parse_mode'] === 'HTML'
                && str_contains($text, 'Hisob-faktura № 125</b> — 12.11.2026')
                && str_contains($text, 'Yetkazib beruvchi:</b> OOO &quot;Alpha&quot; &lt;b&gt; (STIR 301234567)')
                && str_contains($text, '1. Zebra ZD230 — 2 dona × 3 500 000 = 7 000 000')
                && str_contains($text, '2. Labels 58×40 — 10 rulon × 45 000,50 = 450 005')
                && str_contains($text, 'QQS: 894 000,60')
                && str_contains($text, 'Jami: 8 344 005,60 soʻm');
        });
        // The source chat is not recorded as a conversation.
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_not_an_invoice_is_skipped_quietly_or_with_a_note(): void
    {
        $this->replies = ['{"status": "skipped", "reason": "handwritten note"}', '{"status": "skipped", "reason": "receipt"}'];

        $this->receive($this->photo(10));
        $this->assertSame('skipped', InvoiceMessage::sole()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendMessage'));

        InvoiceParserSetting::current()->update(['notify_skipped' => true]);
        $this->receive($this->photo(11));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'Hisob-faktura emas: <code>receipt</code>'));
    }

    public function test_text_and_other_chats_are_left_alone_and_redelivery_is_ignored(): void
    {
        $this->replies = [$this->invoiceJson()];
        $bot = app(TelegramBot::class);

        $bot->handle(['update_id' => 1, 'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'], 'text' => 'hello']]);
        $bot->handle($this->photo(2, '-1003333333333'));
        $this->assertSame(0, InvoiceMessage::count());

        $bot->handle($this->photo(3));
        $bot->handle($this->photo(3)); // Telegram re-delivers the same message
        $this->artisan('invoices:process');
        $this->assertSame(1, InvoiceMessage::count());
        $this->assertCount(1, $this->reads);
    }

    public function test_switched_off_the_source_chat_is_an_ordinary_group(): void
    {
        InvoiceParserSetting::current()->update(['enabled' => false]);

        $this->receive($this->photo());

        $this->assertSame(0, InvoiceMessage::count());
        $this->assertSame(1, ChatConversation::where('channel', 'group')->count());
    }

    public function test_pdf_and_image_documents_are_read_other_files_ignored(): void
    {
        $this->replies = [$this->invoiceJson()];
        $doc = fn (int $id, string $mime) => $this->photo($id, self::SOURCE, ['photo' => null, 'document' => ['file_id' => "doc{$id}", 'mime_type' => $mime]]);

        $this->receive($doc(20, 'application/zip'));
        $this->assertSame(0, InvoiceMessage::count());

        $this->receive($doc(21, 'image/png'));
        $this->assertSame('doc21', InvoiceMessage::sole()->file_id);
    }

    public function test_retryable_failure_is_retried_by_the_scheduler_permanent_one_is_not(): void
    {
        $this->replies = [new InvoiceReadException('Claude API error 529: overloaded', true), $this->invoiceJson()];

        $this->receive($this->photo());
        $invoice = InvoiceMessage::sole();
        $this->assertSame(['failed', 1], [$invoice->status, $invoice->attempts]);

        $this->travel(6)->minutes();
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertSame(['sent', 2], [$invoice->fresh()->status, $invoice->fresh()->attempts]);

        // A refusal / bad request is not retried.
        $this->replies = [new InvoiceReadException('Claude declined to read this image.', false)];
        $this->receive($this->photo(30));
        $this->travel(10)->minutes();
        $this->artisan('invoices:process')->assertSuccessful();
        $bad = InvoiceMessage::where('message_id', 30)->sole();
        $this->assertSame(['failed', InvoiceMessage::MAX_ATTEMPTS], [$bad->status, $bad->attempts]);
        $this->assertCount(3, $this->reads);
    }

    public function test_unexpected_reply_is_a_failure_with_the_text(): void
    {
        $this->replies = ['Sorry, I cannot help.'];

        $this->receive($this->photo());

        $invoice = InvoiceMessage::sole();
        $this->assertSame('failed', $invoice->status);
        $this->assertStringContainsString('Sorry, I cannot help.', $invoice->error);
    }

    public function test_long_invoices_fit_in_one_telegram_message(): void
    {
        $items = array_fill(0, 300, ['name' => str_repeat('Mahsulot ', 4), 'unit' => 'dona', 'quantity' => 1, 'price' => 1000, 'amount' => 1000]);
        $text = InvoiceParser::summary(json_decode($this->invoiceJson(['items' => $items]), true));

        $this->assertLessThanOrEqual(4096, mb_strlen($text));
        $this->assertMatchesRegularExpression('/… yana \d+ ta qator/u', $text);
        $this->assertStringContainsString('Jami:', $text);
    }

    public function test_admin_settings_messages_retry_and_access(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->replies = [$this->invoiceJson(), $this->invoiceJson(['invoice_number' => '126'])];
        $this->receive($this->photo());
        $invoice = InvoiceMessage::sole();

        $this->get(route('admin.invoice-messages.index'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.invoice-parser.edit'))->assertRedirect();

        $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk()->assertSee('№ 125')->assertSee('OOO');
        $this->actingAs($admin)->get(route('admin.invoice-messages.show', $invoice))->assertOk()->assertSee('Zebra ZD230')->assertSee('8 344 005,60');
        $this->actingAs($admin)->get(route('admin.invoice-messages.image', $invoice))->assertOk()->assertHeader('Content-Type', 'image/png');

        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('sk-ant-test-key', $html, 'the key never reaches the page');
        $this->assertStringContainsString('…-key', $html);

        $this->actingAs($admin)->post(route('admin.invoice-messages.retry', $invoice))->assertRedirect();
        $this->assertSame('126', $invoice->fresh()->result['invoice_number']);
    }

    public function test_settings_validation_and_key_handling(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['enabled' => '1', 'daily_limit' => 50, 'model' => 'claude-sonnet-5-5', 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'prompt' => ''];

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['target_chat_id' => self::SOURCE] + $form)->assertSessionHasErrors('target_chat_id');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['source_chat_id' => '@group'] + $form)->assertSessionHasErrors('source_chat_id');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['model' => 'gpt-5'] + $form)->assertSessionHasErrors('model');

        // Blank key field keeps the saved key; the default prompt is not stored.
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['api_key' => '', 'prompt' => InvoiceParserSetting::defaultPrompt()] + $form)->assertSessionHasNoErrors();
        $s = InvoiceParserSetting::current();
        $this->assertSame(['sk-ant-test-key', 'claude-sonnet-5-5', null], [$s->apiKey(), $s->modelId(), $s->prompt]);
        $this->assertNotSame('sk-ant-test-key', $s->getRawOriginal('api_key'), 'stored encrypted');

        // Removing the key with no .env key: it can't stay on.
        config(['services.anthropic.key' => null]);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['remove_key' => '1'] + $form)->assertSessionHasErrors('enabled');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['remove_key' => '1', 'enabled' => '0'] + $form)->assertSessionHasNoErrors();
        $this->assertNull(InvoiceParserSetting::current()->apiKey());

        // The .env key is the fallback.
        config(['services.anthropic.key' => 'sk-ant-from-env']);
        $this->assertSame('sk-ant-from-env', InvoiceParserSetting::current()->apiKey());
    }
}
