<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\ImageRotator;
use App\Services\Invoices\InvoiceParser;
use App\Services\Invoices\InvoiceReadException;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Invoice chat gets the photo (turned upright) and the summary as a reply; the JSON recipient gets the JSON and errors. Reading without cron. */
class InvoiceImageChatTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const TARGET = '-1002222222222';

    /** The JSON recipient: a Telegram user. */
    private const USER = '777000111';

    public int $reads = 0;

    public int $rotation = 90;

    public ?string $system = null;

    /** @var list<array{method: string, chat: string, data: array}> */
    public array $sent = [];

    public bool $photoFails = false;

    public ?string $supplierInn = '301234567';

    public array $failChats = [];

    private string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true, 'services.anthropic.key' => 'sk-ant-env-key']);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'json_chat_id' => self::USER]);

        // A 40×20 landscape JPEG: turned 90°, it becomes 20×40.
        $img = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($img);
        $this->jpeg = (string) ob_get_clean();

        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceImageChatTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads++;
                $this->test->system = $system;

                return ['text' => json_encode(['status' => 'accepted', 'invoice_number' => '15 <b>', 'invoice_date' => '2026-11-17', 'supplier' => ['name' => 'Seller', 'inn' => $this->test->supplierInn],
                    'items' => [], 'total_with_vat' => 10, 'rotation' => $this->test->rotation]), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
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
                    if (! empty($this->failChats[(string) ($data['chat_id'] ?? '')])) {
                        return Http::response(['ok' => false], 400);
                    }
                    if ($method === 'sendPhoto' && $this->photoFails) {
                        return Http::response(['ok' => false, 'description' => 'PHOTO_INVALID_DIMENSIONS'], 400);
                    }
                    $this->sent[] = ['method' => $method, 'chat' => (string) ($data['chat_id'] ?? ''), 'data' => $data];

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

    public function test_photo_turned_upright_then_summary_reply_in_the_invoice_chat_and_json_to_the_user(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $invoice = InvoiceMessage::sole();
        $this->assertSame('sent', $invoice->status);
        $this->assertSame(90, $invoice->result['rotation']);
        $this->assertStringContainsString('ORIENTATION', $this->system, 'the rotation question is always in the prompt');

        [$photo, $summary, $json] = $this->sent;
        $this->assertSame(['sendPhoto', self::TARGET], [$photo['method'], $photo['chat']]);
        $size = getimagesizefromstring($photo['data']['photo']);
        $this->assertSame([20, 40], [$size[0], $size[1]], 'turned 90°');
        $this->assertArrayNotHasKey('caption', $photo['data'], 'the photo only');
        $this->assertSame(['sendMessage', self::TARGET], [$summary['method'], $summary['chat']]);
        $this->assertSame(101, $summary['data']['reply_parameters']['message_id'], 'the summary replies to the photo');
        $this->assertSame(['sendMessage', self::USER], [$json['method'], $json['chat']]);
        $this->assertStringContainsString('&quot;status&quot;: &quot;accepted&quot;', $json['data']['text']);
        $this->assertSame([101, 102, 103], [$invoice->image_message_id, $invoice->sent_message_id, $invoice->json_message_id]);
    }

    public function test_upright_photo_is_posted_as_it_is(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->rotation = 0;
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $this->assertSame($this->jpeg, $this->sent[0]['data']['photo'], 'upright already: the original bytes');
    }

    public function test_photo_refused_as_photo_goes_as_a_file(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->photoFails = true;
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertSame(['sendDocument', self::TARGET], [$this->sent[0]['method'], $this->sent[0]['chat']]);
        $this->assertSame('sent', InvoiceMessage::sole()->status);
    }

    public function test_failed_post_is_retried_without_a_new_read_or_duplicates(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->failChats[self::USER] = true;
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $invoice = InvoiceMessage::sole();
        $this->assertSame('failed', $invoice->status);
        $this->assertStringContainsString('JSON recipient', $invoice->error);
        $this->assertNotNull($invoice->image_message_id);
        $this->assertNotNull($invoice->sent_message_id, 'the invoice chat got everything');

        $this->failChats = [];
        $this->sent = [];
        $invoice->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
        $this->artisan('invoices:process')->assertSuccessful();

        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame(1, $this->reads, 'no second paid read');
        $this->assertSame([['sendMessage', self::USER]], array_map(fn ($s) => [$s['method'], $s['chat']], $this->sent), 'only the missing JSON');
    }

    public function test_final_failure_sends_an_error_json_to_the_user_once(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->app->instance(ClaudeInvoiceReader::class, new class extends ClaudeInvoiceReader
        {
            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                throw new InvoiceReadException('Claude API error 529: overloaded', true);
            }
        });
        $this->photo(1);
        $invoice = InvoiceMessage::sole();
        foreach (range(1, InvoiceMessage::MAX_ATTEMPTS) as $attempt) {
            InvoiceMessage::whereKey($invoice->id)->update(['updated_at' => now()->subMinutes(10)]);
            $this->artisan('invoices:process')->assertSuccessful();
            $this->assertCount($attempt < InvoiceMessage::MAX_ATTEMPTS ? 0 : 1, $this->sent, "attempt {$attempt}: only the last failure is reported");
        }

        $this->assertSame(['sendMessage', self::USER], [$this->sent[0]['method'], $this->sent[0]['chat']]);
        $json = json_decode(html_entity_decode(strip_tags($this->sent[0]['data']['text'])), true);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Claude API error 529: overloaded', $json['error']);
        $this->assertSame(InvoiceMessage::MAX_ATTEMPTS, $json['attempts']);
        $this->assertSame(['chat_id' => self::SOURCE, 'message_id' => 1, 'from_id' => '555', 'from' => 'Ali'], $json['source']);

        // Leftover runs do not report it again.
        InvoiceMessage::whereKey($invoice->id)->update(['updated_at' => now()->subMinutes(10)]);
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertCount(1, $this->sent);
    }

    public function test_non_retryable_failure_is_reported_at_once(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        InvoiceParserSetting::current()->update(['daily_limit' => 1]);
        $this->photo(1);
        $this->photo(2);
        $this->artisan('invoices:process')->assertSuccessful();

        $errors = array_values(array_filter($this->sent, fn ($s) => str_contains((string) ($s['data']['text'] ?? ''), '&quot;error&quot;')));
        $this->assertCount(1, $errors);
        $this->assertSame(self::USER, $errors[0]['chat']);
        $this->assertStringContainsString('Daily limit', $errors[0]['data']['text']);
    }

    public function test_rotation_values_are_cleaned(): void
    {
        foreach ([[90, 90], ['270', 270], [-90, 270], [450, 90], [45, 0], ['x', 0], [null, 0], [180.0, 180]] as [$in, $out]) {
            $this->assertSame($out, InvoiceParser::rotation($in), var_export($in, true));
        }
        $this->assertSame(['body' => 'pdf', 'mime' => 'application/pdf', 'rotated' => false], ImageRotator::upright('pdf', 'application/pdf', 90));
        $this->assertFalse(ImageRotator::upright('not an image', 'image/jpeg', 90)['rotated']);
    }

    public function test_without_the_scheduler_the_webhook_reads_the_photo_itself(): void
    {
        Cache::forget(InvoiceParser::HEARTBEAT);
        $this->photo(1);

        $this->assertSame(1, $this->reads);
        $this->assertSame('sent', InvoiceMessage::sole()->status);
    }

    public function test_with_the_scheduler_running_the_webhook_only_stores(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->photo(1);

        $this->assertSame(0, $this->reads);
        $this->assertSame('pending', InvoiceMessage::sole()->status);
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertSame(1, $this->reads);
    }

    public function test_the_scheduler_run_sets_the_heartbeat(): void
    {
        Cache::forget(InvoiceParser::HEARTBEAT);
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertTrue(InvoiceParser::schedulerRunning());
    }

    public function test_listed_seller_goes_to_the_invoice_chat(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        InvoiceParserSetting::current()->update(['seller_inns' => '301234567, 302000000']);
        $this->supplierInn = '301 234 567';
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $this->assertSame([self::TARGET, self::TARGET, self::USER], array_column($this->sent, 'chat'));
        $this->assertStringNotContainsString('roʻyxatda yoʻq', $this->sent[1]['data']['text']);
    }

    public function test_other_seller_goes_to_the_user_not_the_invoice_chat(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        InvoiceParserSetting::current()->update(['seller_inns' => '301234567']);
        $this->supplierInn = '999888777';
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $this->assertSame([self::USER, self::USER, self::USER], array_column($this->sent, 'chat'), 'photo, summary and JSON to the user');
        $this->assertSame('sendPhoto', $this->sent[0]['method']);
        $this->assertStringStartsWith('⚠️ Sotuvchi roʻyxatda yoʻq', $this->sent[1]['data']['text']);
        $this->assertSame('sent', InvoiceMessage::sole()->status);
    }

    public function test_other_seller_without_a_user_is_only_listed(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        InvoiceParserSetting::current()->update(['seller_inns' => '301234567', 'json_chat_id' => null]);
        $this->supplierInn = null;
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $this->assertSame([], $this->sent);
        $invoice = InvoiceMessage::sole();
        $this->assertSame('other_seller', $invoice->status);
        $this->assertStringContainsString('not in the Sellers list', $invoice->error);
        $this->assertSame('user', $invoice->result['route']);
    }

    public function test_sellers_setting_is_validated_and_normalised(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['model' => InvoiceParserSetting::DEFAULT_MODEL, 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET];
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['seller_inns' => "301234567;302000000\n301234567"])->assertSessionHasNoErrors();
        $this->assertSame('301234567, 302000000', InvoiceParserSetting::current()->seller_inns);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['seller_inns' => 'OOO Alpha'])->assertSessionHasErrors('seller_inns');
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->assertSee('Sellers');
    }

    public function test_settings_have_no_image_chat_and_warn_without_scheduler(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()
            ->assertDontSee('Image chat ID')->assertSee('JSON recipient')->assertSee('The scheduler has not run');

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), [
            'enabled' => 1, 'model' => InvoiceParserSetting::DEFAULT_MODEL, 'source_chat_id' => self::SOURCE,
            'target_chat_id' => self::TARGET, 'json_chat_id' => self::USER,
        ])->assertSessionHasNoErrors();
        $this->assertSame(self::USER, InvoiceParserSetting::current()->json_chat_id);

        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->assertDontSee('The scheduler has not run');
    }
}
