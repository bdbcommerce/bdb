<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\InvoiceParser;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** QA: Sellers list, error JSON to the JSON recipient, settings changes between attempts. */
class InvoiceSellerErrorJsonQaTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const TARGET = '-1002222222222';

    private const USER = '777000111';

    public int $reads = 0;

    /** @var array|string|null what Claude returns (array = JSON-encoded) */
    public mixed $reply = null;

    public array $sent = [];

    public array $failChats = [];

    public bool $throwOnUser = false;

    public bool $summaryFails = false;

    private string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true, 'services.anthropic.key' => 'sk-ant-env-key']);
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'json_chat_id' => self::USER]);

        $img = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($img);
        $this->jpeg = (string) ob_get_clean();

        $this->reply = ['status' => 'accepted', 'supplier' => ['name' => 'Seller', 'inn' => '301234567'], 'items' => [], 'total_with_vat' => 10];

        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceSellerErrorJsonQaTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads++;
                $text = is_array($this->test->reply) ? json_encode($this->test->reply) : (string) $this->test->reply;

                return ['text' => $text, 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/1.jpg']]);
            }
            if (str_contains($url, '/file/')) {
                return Http::response($this->jpeg);
            }
            foreach (['sendMessage', 'sendDocument', 'sendPhoto'] as $method) {
                if (str_contains($url, '/'.$method)) {
                    $data = $request->isMultipart()
                        ? collect($request->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']])->all()
                        : $request->data();
                    $chat = (string) ($data['chat_id'] ?? '');
                    if ($this->throwOnUser && $chat === self::USER) {
                        throw new ConnectionException('boom');
                    }
                    if ($this->summaryFails && $method === 'sendMessage' && $chat === self::TARGET) {
                        return Http::response(['ok' => false], 400);
                    }
                    if (! empty($this->failChats[$chat])) {
                        return Http::response(['ok' => false], 400);
                    }
                    $this->sent[] = ['method' => $method, 'chat' => $chat, 'data' => $data];

                    return Http::response(['ok' => true, 'result' => ['message_id' => 100 + count($this->sent)]]);
                }
            }

            return Http::response(['ok' => true, 'result' => []]);
        });
    }

    private function photo(int $id): void
    {
        app(TelegramBot::class)->handle(['update_id' => $id, 'message' => [
            'message_id' => $id, 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'], 'from' => ['id' => 555, 'first_name' => 'Ali'],
            'photo' => [['file_id' => "f{$id}", 'width' => 40]],
        ]]);
    }

    private function runAll(InvoiceMessage $invoice): void
    {
        foreach (range(1, InvoiceMessage::MAX_ATTEMPTS + 1) as $i) {
            InvoiceMessage::whereKey($invoice->id)->update(['updated_at' => now()->subMinutes(10)]);
            $this->artisan('invoices:process')->assertSuccessful();
        }
    }

    public function test_allows_seller_with_odd_inn_values(): void
    {
        $s = new InvoiceParserSetting(['seller_inns' => '301234567, 000123']);
        $this->assertTrue($s->allowsSeller(['supplier' => ['inn' => 301234567]]), 'int');
        $this->assertTrue($s->allowsSeller(['supplier' => ['inn' => 'STIR: 301-234-567']]), 'labels and dashes');
        $this->assertTrue($s->allowsSeller(['supplier' => ['inn' => '000123']]), 'leading zeros kept');
        $this->assertFalse($s->allowsSeller(['supplier' => ['inn' => '123']]), 'no leading zeros = different');
        $this->assertFalse($s->allowsSeller(['supplier' => ['inn' => '3012345670']]), 'no partial match');
        $this->assertFalse($s->allowsSeller(['supplier' => 'x']));
        $this->assertFalse($s->allowsSeller([]));
        $this->assertFalse($s->allowsSeller(['supplier' => ['inn' => '']]));
        $this->assertTrue((new InvoiceParserSetting(['seller_inns' => '  ']))->allowsSeller([]), 'blank list = all');
    }

    public function test_error_json_escapes_raw_claude_text(): void
    {
        $this->reply = 'Sorry </code></pre><a href="https://evil">x</a> @admin';
        $this->photo(1);
        $invoice = InvoiceMessage::sole();
        $this->runAll($invoice);

        $this->assertSame('failed', $invoice->fresh()->status);
        $errors = array_values(array_filter($this->sent, fn ($s) => $s['chat'] === self::USER));
        $this->assertCount(1, $errors, 'error JSON sent exactly once');
        $text = $errors[0]['data']['text'];
        $this->assertStringNotContainsString('<a ', $text);
        $this->assertSame(1, substr_count($text, '</code>'), 'raw </code> is escaped');
        $json = json_decode(html_entity_decode(strip_tags($text)), true);
        $this->assertSame('error', $json['status']);
        $this->assertStringContainsString('<a href="https://evil">', $json['error']);
        $this->assertSame(InvoiceMessage::MAX_ATTEMPTS, $this->reads, 'one paid read per attempt');
    }

    public function test_error_json_with_invalid_utf8_is_not_empty(): void
    {
        $invoice = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 9, 'file_id' => 'f', 'error' => "bad \xB1\x31 bytes", 'attempts' => 3]);
        $json = InvoiceParser::errorJson($invoice);
        $this->assertNotSame('', $json, 'errorJson must not be empty for invalid UTF-8 in the error text');
    }

    public function test_error_json_send_crash_does_not_break_the_failure_path(): void
    {
        $this->throwOnUser = true;
        $this->reply = 'not json';
        $this->photo(1);
        $invoice = InvoiceMessage::sole();
        $this->runAll($invoice);

        $invoice->refresh();
        $this->assertSame('failed', $invoice->status);
        $this->assertSame(InvoiceMessage::MAX_ATTEMPTS, $invoice->attempts);
        $this->assertStringContainsString('Claude did not return the expected JSON', $invoice->error);
    }

    public function test_other_seller_summary_with_warning_stays_within_telegram_limit(): void
    {
        InvoiceParserSetting::current()->update(['seller_inns' => '1']);
        $items = array_fill(0, 500, ['name' => str_repeat('Ж', 300), 'quantity' => 1, 'price' => 1, 'amount' => 1]);
        $this->reply = ['status' => 'accepted', 'supplier' => ['name' => str_repeat('&', 200), 'inn' => '2'], 'items' => $items, 'total_with_vat' => 1];
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $summary = collect($this->sent)->firstWhere('method', 'sendMessage');
        $this->assertSame(self::USER, $summary['chat']);
        $this->assertLessThanOrEqual(4096, mb_strlen($summary['data']['text']));
        $this->assertSame('sent', InvoiceMessage::sole()->status);
    }

    public function test_total_falls_back_to_total_amount_when_with_vat_not_numeric(): void
    {
        $s = InvoiceParser::summary(['status' => 'accepted', 'total_with_vat' => 'abc', 'total_amount' => 5]);
        $this->assertStringContainsString('Jami: 5 soʻm', $s);
        $s = InvoiceParser::summary(['status' => 'accepted', 'total_with_vat' => 0, 'total_amount' => 5]);
        $this->assertStringContainsString('Jami: 0 soʻm', $s, '0 is a value');
        foreach (['Hisob-faktura', 'Xaridor', 'QQS:', 'AI oʻqidi'] as $gone) {
            $this->assertStringNotContainsString($gone, InvoiceParser::summary(['status' => 'accepted', 'buyer' => ['name' => 'B'], 'total_vat' => 1, 'warning' => 'w', 'invoice_number' => '1']));
        }
    }

    /** Photo reached the invoice chat, the summary failed; then the seller is removed from the list before the retry. */
    public function test_seller_list_changed_between_attempts(): void
    {
        $this->summaryFails = true;
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();
        $invoice = InvoiceMessage::sole();
        $this->assertSame('failed', $invoice->status);
        $this->assertNotNull($invoice->image_message_id);
        $photoId = $invoice->image_message_id;

        InvoiceParserSetting::current()->update(['seller_inns' => '999']);
        $this->summaryFails = false;
        $this->sent = [];
        InvoiceMessage::whereKey($invoice->id)->update(['updated_at' => now()->subMinutes(10)]);
        $this->artisan('invoices:process')->assertSuccessful();

        // The route chosen on the first post is kept: the summary joins its photo in the invoice chat, as a reply.
        $summary = collect($this->sent)->firstWhere('method', 'sendMessage');
        $this->assertSame(self::TARGET, $summary['chat']);
        $this->assertSame($photoId, $summary['data']['reply_parameters']['message_id']);
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    /** The last attempt crashed mid-way (stale "processing"): the JSON recipient is never told. */
    public function test_crashed_last_attempt_reports_error_json(): void
    {
        $invoice = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 1, 'file_id' => 'f', 'status' => 'processing', 'attempts' => InvoiceMessage::MAX_ATTEMPTS]);
        InvoiceMessage::whereKey($invoice->id)->update(['updated_at' => now()->subMinutes(20)]);
        $this->artisan('invoices:process')->assertSuccessful();

        $this->assertSame('failed', $invoice->fresh()->status);
        $this->assertCount(1, array_filter($this->sent, fn ($s) => $s['chat'] === self::USER), 'no error JSON when the last attempt crashed');
    }

    public function test_seller_inns_migration_down_and_up(): void
    {
        $path = 'database/migrations/2026_11_17_300000_add_seller_inns_to_invoice_parser.php';
        $this->assertTrue(Schema::hasColumn('invoice_parser_settings', 'seller_inns'));
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('invoice_parser_settings', 'seller_inns'));
        Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        $this->assertTrue(Schema::hasColumn('invoice_parser_settings', 'seller_inns'));
    }

    public function test_settings_page_access_old_input_and_long_list(): void
    {
        $this->get(route('admin.invoice-parser.edit'))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.invoice-parser.edit'))->assertRedirect(route('admin.login'));

        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['model' => InvoiceParserSetting::DEFAULT_MODEL, 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET];
        $this->actingAs($admin)->from(route('admin.invoice-parser.edit'))
            ->put(route('admin.invoice-parser.update'), $form + ['seller_inns' => '30123-4567'])
            ->assertRedirect(route('admin.invoice-parser.edit'))->assertSessionHasErrors('seller_inns');
        $this->actingAs($admin)->from(route('admin.invoice-parser.edit'))->followingRedirects()
            ->put(route('admin.invoice-parser.update'), $form + ['seller_inns' => '30123-4567'])
            ->assertSee('value="30123-4567"', false)->assertSee('Seller STIRs are numbers');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['seller_inns' => str_repeat('1', 1001)])->assertSessionHasErrors('seller_inns');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['seller_inns' => '-301234567'])->assertSessionHasErrors('seller_inns');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['seller_inns' => ''])->assertSessionHasNoErrors();
        $this->assertNull(InvoiceParserSetting::current()->seller_inns);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['image_chat_id' => '-1009'])->assertSessionHasNoErrors();
    }
}
