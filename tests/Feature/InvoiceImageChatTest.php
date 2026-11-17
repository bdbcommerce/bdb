<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\ImageRotator;
use App\Services\Invoices\InvoiceParser;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Image chat: the original photo, turned upright, with the summary as a reply when the chats are the same. Reading without cron. */
class InvoiceImageChatTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const TARGET = '-1002222222222';

    private const IMAGES = '-1004444444444';

    public int $reads = 0;

    public int $rotation = 90;

    public ?string $system = null;

    /** @var list<array{method: string, chat: string, data: array}> */
    public array $sent = [];

    public bool $photoFails = false;

    public array $failChats = [];

    private string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true, 'services.anthropic.key' => 'sk-ant-env-key']);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'image_chat_id' => self::IMAGES]);

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

                return ['text' => json_encode(['status' => 'accepted', 'invoice_number' => '15 <b>', 'invoice_date' => '2026-11-17',
                    'items' => [], 'total_with_vat' => 10, 'rotation' => $this->test->rotation]), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'p/1.jpg']]);
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

    public function test_photo_is_turned_upright_and_posted_to_the_image_chat(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $invoice = InvoiceMessage::sole();
        $this->assertSame('sent', $invoice->status);
        $this->assertSame(90, $invoice->result['rotation']);
        $this->assertStringContainsString('ORIENTATION', $this->system, 'the rotation question is always in the prompt');

        [$photo, $summary] = $this->sent;
        $this->assertSame(['sendPhoto', self::IMAGES], [$photo['method'], $photo['chat']]);
        $size = getimagesizefromstring($photo['data']['photo']);
        $this->assertSame([20, 40], [$size[0], $size[1]], 'turned 90°');
        $this->assertStringContainsString('№ <code>15 &lt;b&gt;</code> — <code>17.11.2026</code>', $photo['data']['caption']);
        $this->assertStringContainsString('90°', $photo['data']['caption']);
        $this->assertSame(['sendMessage', self::TARGET], [$summary['method'], $summary['chat']]);
        $this->assertArrayNotHasKey('reply_parameters', $summary['data'], 'different chats: no reply');
        $this->assertSame(101, $invoice->image_message_id);
    }

    public function test_same_chat_summary_replies_to_the_photo(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        InvoiceParserSetting::current()->update(['image_chat_id' => self::TARGET]);
        $this->rotation = 0;
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        [$photo, $summary] = $this->sent;
        $this->assertSame([self::TARGET, self::TARGET], [$photo['chat'], $summary['chat']]);
        $this->assertSame(101, $summary['data']['reply_parameters']['message_id']);
        $this->assertSame($this->jpeg, $photo['data']['photo'], 'upright already: the original bytes');
        $this->assertStringNotContainsString('°', $photo['data']['caption']);
    }

    public function test_photo_refused_as_photo_goes_as_a_file(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->photoFails = true;
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertSame('sendDocument', $this->sent[0]['method']);
        $this->assertSame('sent', InvoiceMessage::sole()->status);

    }

    public function test_failed_image_is_retried_without_a_new_read(): void
    {
        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->failChats[self::IMAGES] = true;
        $this->photo(1);
        $this->artisan('invoices:process')->assertSuccessful();

        $invoice = InvoiceMessage::sole();
        $this->assertSame('failed', $invoice->status);
        $this->assertStringContainsString('image chat', $invoice->error);
        $this->assertNotNull($invoice->sent_message_id, 'the summary went out anyway');

        $this->failChats = [];
        $this->sent = [];
        $invoice->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
        $this->artisan('invoices:process')->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('sent', $invoice->status);
        $this->assertSame(1, $this->reads, 'no second paid read');
        $this->assertSame([['sendPhoto', self::IMAGES]], array_map(fn ($s) => [$s['method'], $s['chat']], $this->sent), 'only the missing photo');
        $this->assertNotNull($invoice->image_message_id);
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

    public function test_settings_save_the_image_chat_and_warn_without_scheduler(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()
            ->assertSee('Image chat ID')->assertSee('The scheduler has not run');

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), [
            'enabled' => 1, 'model' => InvoiceParserSetting::DEFAULT_MODEL, 'source_chat_id' => self::SOURCE,
            'target_chat_id' => self::TARGET, 'image_chat_id' => self::TARGET,
        ])->assertSessionHasNoErrors();
        $this->assertSame(self::TARGET, InvoiceParserSetting::current()->image_chat_id);

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), [
            'model' => InvoiceParserSetting::DEFAULT_MODEL, 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'image_chat_id' => self::SOURCE,
        ])->assertSessionHasErrors('image_chat_id');

        Cache::put(InvoiceParser::HEARTBEAT, 'now', 180);
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->assertDontSee('The scheduler has not run');
    }
}
