<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\InvoiceParser;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Security review of the invoice parser. Tests named test_finding_* reproduce open findings
 * and are expected to FAIL until the finding is fixed; the others confirm controls that hold.
 * Never calls Claude or Telegram: the reader is replaced and every HTTP request is faked.
 */
class SecurityInvoiceParserTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const TARGET = '-1002222222222';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public array $reads = [];

    /** @var list<string> */
    public array $replies = [];

    private string $fileBody;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:SECRETTOKEN', 'services.telegram.replies' => true, 'services.anthropic.key' => null]);
        InvoiceParserSetting::current()->update([
            'enabled' => true, 'api_key' => 'sk-ant-api03-SECRETSECRETSECRET', 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET,
        ]);

        $test = $this;
        $this->app->instance(ClaudeInvoiceReader::class, new class($test) extends ClaudeInvoiceReader
        {
            public function __construct(private SecurityInvoiceParserTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads[] = compact('mime');

                return ['text' => array_shift($this->test->replies) ?? '{"status":"skipped","reason":"x"}', 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });

        $this->fileBody = base64_decode(self::PNG);
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/file_1.bin']]),
            'api.telegram.org/file/*' => fn () => Http::response($this->fileBody, 200, ['Content-Type' => 'application/octet-stream']),
            'api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function photo(int $id = 10, array $extra = []): array
    {
        return ['update_id' => $id, 'message' => $extra + [
            'message_id' => $id, 'date' => time(), 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'],
            'from' => ['id' => 5, 'first_name' => 'Ali'],
            'photo' => [['file_id' => 'big'.$id, 'width' => 1280, 'file_size' => 90000]],
        ]];
    }

    private function invoice(array $over = []): string
    {
        return json_encode($over + [
            'status' => 'accepted', 'invoice_number' => '1', 'invoice_date' => '2026-11-12',
            'supplier' => ['name' => 'S', 'inn' => '1'], 'buyer' => ['name' => 'B', 'inn' => '2'],
            'items' => [['name' => 'X', 'unit' => 'dona', 'quantity' => 1, 'price' => 1, 'amount' => 1]],
            'total_with_vat' => 1,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function sentTexts(): array
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]['text'])->values()->all();
    }

    // ---------------------------------------------------------------- controls that hold

    public function test_every_invoice_route_refuses_guests_and_non_admins(): void
    {
        $invoice = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 1, 'file_id' => 'f', 'mime' => 'image/jpeg']);
        $routes = [
            ['get', route('admin.invoice-parser.edit')],
            ['put', route('admin.invoice-parser.update')],
            ['post', route('admin.invoice-parser.test')],
            ['get', route('admin.invoice-messages.index')],
            ['get', route('admin.invoice-messages.show', $invoice)],
            ['get', route('admin.invoice-messages.image', $invoice)],
            ['post', route('admin.invoice-messages.retry', $invoice)],
            ['delete', route('admin.invoice-messages.destroy', $invoice)],
        ];

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('admin.login'));
        }
        $user = User::factory()->create(['is_admin' => false]);
        foreach ($routes as [$method, $url]) {
            $status = $this->actingAs($user)->{$method}($url)->status();
            $this->assertTrue(in_array($status, [302, 403], true), "$method $url gave $status to a non-admin");
        }
        $this->assertSame(0, count($this->reads));
        $this->assertNotNull($invoice->fresh(), 'not deleted');
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    public function test_webhook_without_the_secret_cannot_inject_source_chat_photos(): void
    {
        config(['services.telegram.webhook_secret' => 'whsec']);
        $this->postJson('/telegram/webhook', $this->photo())->assertForbidden();
        $this->postJson('/telegram/webhook', $this->photo(11), ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
        config(['services.telegram.webhook_secret' => '']);
        $this->postJson('/telegram/webhook', $this->photo(12), ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertForbidden();

        $this->assertSame(0, InvoiceMessage::count());
        $this->assertSame([], $this->reads);
    }

    public function test_injected_html_is_escaped_in_the_telegram_summary_and_admin_pages(): void
    {
        $xss = '<a href="tg://resolve?domain=x">pay</a><script>alert(1)</script>';
        $this->replies = [$this->invoice([
            'invoice_number' => $xss, 'invoice_date' => $xss, 'warning' => $xss,
            'supplier' => ['name' => $xss, 'inn' => $xss, 'vat_code' => $xss], 'buyer' => ['name' => $xss, 'inn' => $xss],
            'items' => [['name' => $xss, 'unit' => $xss, 'quantity' => $xss, 'price' => $xss, 'amount' => $xss, 'vat_rate' => $xss]],
            'amount_in_words' => $xss,
        ])];
        app(TelegramBot::class)->handle($this->photo(20, ['caption' => $xss, 'from' => ['id' => 5, 'first_name' => $xss]]));
        $this->artisan('invoices:process');

        $invoice = InvoiceMessage::sole();
        $this->assertSame('sent', $invoice->status);
        foreach ($this->sentTexts() as $text) {
            $this->assertStringNotContainsString('<a ', $text);
            $this->assertStringNotContainsString('<script', $text);
        }

        $admin = User::factory()->create(['is_admin' => true]);
        foreach ([route('admin.invoice-messages.index'), route('admin.invoice-messages.show', $invoice)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
            $this->assertStringNotContainsString('<a href="tg://', $html, $url);
        }
    }

    public function test_skipped_reason_is_escaped_in_the_note_to_the_destination_chat(): void
    {
        InvoiceParserSetting::current()->update(['notify_skipped' => true]);
        $this->replies = [json_encode(['status' => 'skipped', 'reason' => '<a href="https://evil.example">x</a>'])];
        app(TelegramBot::class)->handle($this->photo(30));
        $this->artisan('invoices:process');

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertStringNotContainsString('<a ', $texts[0]);
    }

    public function test_proxy_never_serves_active_content(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->fileBody = '<html><script>alert(document.cookie)</script></html>';

        // HTML uploaded as a "PNG" document: captured with the declared type, served as an inert image.
        app(InvoiceParser::class)->capture(['message_id' => 40, 'chat' => ['id' => (int) self::SOURCE],
            'document' => ['file_id' => 'html', 'mime_type' => 'image/png']]);
        $doc = InvoiceMessage::sole();
        $r = $this->actingAs($admin)->get(route('admin.invoice-messages.image', $doc))->assertOk();
        $r->assertHeader('Content-Type', 'image/png');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', $r->headers->get('Content-Security-Policy'));

        // A stored type outside the whitelist falls back to image/jpeg.
        $doc->update(['mime' => 'text/html']);
        $this->actingAs($admin)->get(route('admin.invoice-messages.image', $doc))->assertHeader('Content-Type', 'image/jpeg');

        // PDF: sandboxed too.
        $doc->update(['mime' => 'application/pdf']);
        $r = $this->actingAs($admin)->get(route('admin.invoice-messages.image', $doc));
        $this->assertStringContainsString('sandbox', $r->headers->get('Content-Security-Policy'));

        // SVG / HTML documents are not captured at all.
        foreach (['image/svg+xml', 'text/html', 'application/xhtml+xml'] as $i => $mime) {
            $this->assertNull(app(InvoiceParser::class)->capture(['message_id' => 50 + $i, 'chat' => ['id' => (int) self::SOURCE],
                'document' => ['file_id' => 'svg'.$i, 'mime_type' => $mime]]));
        }
    }

    public function test_html_disguised_as_image_is_not_sent_to_claude_as_html(): void
    {
        $this->fileBody = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>';
        app(TelegramBot::class)->handle($this->photo(60, ['photo' => null, 'document' => ['file_id' => 'svg', 'mime_type' => 'image/png']]));
        $this->artisan('invoices:process');

        // finfo sees SVG, which is not allowed, so the declared whitelisted type is kept.
        $this->assertSame('image/png', InvoiceMessage::sole()->mime);
    }

    public function test_api_key_never_reaches_html_json_or_error_text(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRETSECRET', $html);
        $this->assertStringNotContainsString('SECRETTOKEN', $html);

        // .env fallback is not shown either.
        InvoiceParserSetting::current()->update(['api_key' => null]);
        config(['services.anthropic.key' => 'sk-ant-api03-ENVSECRETENVSECRET']);
        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('ENVSECRETENV', $html);

        $this->assertArrayNotHasKey('api_key', InvoiceParserSetting::current()->toArray());

        $clean = (new \ReflectionMethod(ClaudeInvoiceReader::class, 'clean'))->invoke(new ClaudeInvoiceReader, 'bad key sk-ant-api03-X here', 'sk-ant-api03-X');
        $this->assertSame('bad key *** here', $clean);
    }

    // ---------------------------------------------------------------- open findings (expected to FAIL)

    /** Finding: header/footer fields are never shortened, so the message can exceed 4096 and every send fails → 3 Claude reads. */
    public function test_finding_summary_always_fits_telegram_limit(): void
    {
        $long = str_repeat('W', 5000);
        $text = InvoiceParser::summary(json_decode($this->invoice(['warning' => $long, 'supplier' => ['name' => $long]]), true));

        $this->assertLessThanOrEqual(4096, mb_strlen($text), 'summary longer than Telegram allows: sendMessage fails and the photo is re-read by Claude up to MAX_ATTEMPTS times');
    }

    /** Finding: a non-string field in Claude's JSON (steerable by text in the image) makes the admin list and detail pages 500. */
    public function test_finding_non_string_fields_do_not_break_admin_pages(): void
    {
        $this->replies = [$this->invoice(['invoice_number' => ['a' => 'b'], 'supplier' => ['name' => ['x'], 'inn' => ['y']], 'warning' => ['z']])];
        app(TelegramBot::class)->handle($this->photo(70));
        $this->artisan('invoices:process');
        $invoice = InvoiceMessage::sole();
        $this->assertSame('sent', $invoice->status);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.invoice-messages.show', $invoice))->assertOk();
    }

    /** Finding: after an APP_KEY change the settings view decrypts api_key directly and the page 500s (apiKey() handles it, the view does not). */
    public function test_finding_settings_page_survives_an_undecryptable_key(): void
    {
        DB::table('invoice_parser_settings')->where('id', 1)->update(['api_key' => 'not-a-valid-payload']);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk();
    }

    /** Finding: no cap on how many photos are sent to Claude — anyone in the source chat can flood it and run up the API bill. */
    public function test_finding_flooding_the_source_chat_is_capped(): void
    {
        InvoiceParserSetting::current()->update(['daily_limit' => 10]);
        $this->replies = array_fill(0, 30, $this->invoice());
        for ($i = 1; $i <= 30; $i++) {
            app(TelegramBot::class)->handle($this->photo(100 + $i));
        }
        $this->artisan('invoices:process', ['--limit' => 50]);

        $this->assertCount(10, $this->reads, 'the daily limit caps paid reads');
        $this->assertSame(20, InvoiceMessage::where('status', 'failed')->where('error', 'like', 'Daily limit%')->count());
    }

    /** Finding: injected URLs / @mentions / bot commands reach the staff chat as live entities (Telegram auto-links them). */
    public function test_finding_injected_links_and_mentions_are_neutralised_in_the_summary(): void
    {
        $this->replies = [$this->invoice(['warning' => 'Bank changed, pay via https://evil.example/pay and ping @attacker'])];
        app(TelegramBot::class)->handle($this->photo(200));
        $this->artisan('invoices:process');

        $text = $this->sentTexts()[0];
        // Inside <code> Telegram makes no link or mention entities.
        $this->assertMatchesRegularExpression('#<code>[^<]*https://evil.example[^<]*@attacker[^<]*</code>#', $text);
        $this->assertSame(1, substr_count($text, 'evil.example'));
    }
}
