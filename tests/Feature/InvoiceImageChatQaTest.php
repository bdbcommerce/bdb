<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\ImageRotator;
use App\Services\Invoices\InvoiceParser;
use App\Services\Telegram\TelegramApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** QA: rotation direction, multipart JSON encoding, drain termination, JSON output, migration up/down. */
class InvoiceImageChatQaTest extends TestCase
{
    use RefreshDatabase;

    public int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.anthropic.key' => 'sk-ant-env-key']);
        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceImageChatQaTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads++;

                return ['text' => json_encode(['status' => 'accepted', 'invoice_number' => '1', 'items' => []]), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    /** 2×1 PNG: left pixel red, right pixel blue. */
    private function png(): string
    {
        $img = imagecreatetruecolor(2, 1);
        imagesetpixel($img, 0, 0, imagecolorallocate($img, 255, 0, 0));
        imagesetpixel($img, 1, 0, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function rgbAt(string $png, int $x, int $y): array
    {
        $img = imagecreatefromstring($png);
        $c = imagecolorat($img, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    public function test_rotation_direction_is_clockwise(): void
    {
        // 90° clockwise: the left (red) pixel ends up on top.
        $r = ImageRotator::upright($this->png(), 'image/png', 90);
        $this->assertTrue($r['rotated']);
        $this->assertSame([1, 2], array_slice(getimagesizefromstring($r['body']), 0, 2));
        $this->assertSame([255, 0, 0], $this->rgbAt($r['body'], 0, 0), '90 cw: red on top');
        $this->assertSame([0, 0, 255], $this->rgbAt($r['body'], 0, 1));

        // 270° clockwise: red ends up at the bottom.
        $r = ImageRotator::upright($this->png(), 'image/png', 270);
        $this->assertSame([0, 0, 255], $this->rgbAt($r['body'], 0, 0), '270 cw: blue on top');
        $this->assertSame([255, 0, 0], $this->rgbAt($r['body'], 0, 1));

        // 180°: red on the right.
        $r = ImageRotator::upright($this->png(), 'image/png', 180);
        $this->assertSame([0, 0, 255], $this->rgbAt($r['body'], 0, 0));
        $this->assertSame([255, 0, 0], $this->rgbAt($r['body'], 1, 0));
    }

    public function test_gif_and_unknown_angles_are_untouched(): void
    {
        $img = imagecreatetruecolor(4, 2);
        ob_start();
        imagegif($img);
        $gif = (string) ob_get_clean();
        $this->assertFalse(ImageRotator::upright($gif, 'image/gif', 90)['rotated']);
        $this->assertFalse(ImageRotator::upright($this->png(), 'image/png', 45)['rotated']);
        $this->assertFalse(ImageRotator::upright($this->png(), 'image/png', 0)['rotated']);
    }

    public function test_upload_json_encodes_nested_extra_and_keeps_strings(): void
    {
        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = collect($request->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']])->all() + ['__url' => $request->url()];

            return Http::response(['ok' => true, 'result' => ['message_id' => 7]]);
        });
        $api = app(TelegramApi::class);
        $this->assertSame(7, $api->sendPhoto('-100', 'bytes', 'a.jpg', ['caption' => 'x <b>', 'reply_parameters' => ['message_id' => 5]])['message_id']);
        $this->assertSame(7, $api->sendDocument('-100', '{}', 'a.json', ['caption' => 'c', 'parse_mode' => 'HTML'])['message_id']);

        $this->assertStringEndsWith('/sendPhoto', $seen[0]['__url']);
        $this->assertSame('{"message_id":5}', $seen[0]['reply_parameters']);
        $this->assertSame('x <b>', $seen[0]['caption']);
        $this->assertSame('bytes', $seen[0]['photo']);
        $this->assertStringEndsWith('/sendDocument', $seen[1]['__url']);
        $this->assertSame('{}', $seen[1]['document']);
        $this->assertSame('HTML', $seen[1]['parse_mode']);
    }

    public function test_drain_stops_when_process_leaves_status_unchanged(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => '-1001', 'target_chat_id' => '-1002']);
        $invoice = InvoiceMessage::create(['chat_id' => '-1001', 'message_id' => 1, 'file_id' => 'f', 'mime' => 'image/jpeg']);

        // The per-message lock is held (e.g. "Read again" is reading it): process() returns at once.
        $lock = Cache::lock("invoice-message:{$invoice->id}", 300);
        $this->assertTrue($lock->get());
        $done = app(InvoiceParser::class)->drain(5);
        $lock->release();

        $this->assertSame(0, $done, 'bounded by the limit, and skipped photos are not counted as done');
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, $this->reads);
    }

    public function test_drain_returns_zero_when_disabled_or_worker_lock_held(): void
    {
        InvoiceParserSetting::current()->update(['enabled' => false, 'source_chat_id' => '-1001', 'target_chat_id' => '-1002']);
        InvoiceMessage::create(['chat_id' => '-1001', 'message_id' => 1, 'file_id' => 'f', 'mime' => 'image/jpeg']);
        $this->assertSame(0, app(InvoiceParser::class)->drain(5));

        InvoiceParserSetting::current()->update(['enabled' => true]);
        $lock = Cache::lock('invoice-parser:worker', 900);
        $this->assertTrue($lock->get());
        $this->assertSame(0, app(InvoiceParser::class)->drain(5));
        $lock->release();
        $this->assertSame(0, $this->reads);
    }

    public function test_json_output_contains_rotation(): void
    {
        $invoice = InvoiceMessage::create(['chat_id' => '-1001', 'message_id' => 1, 'file_id' => 'f', 'mime' => 'image/jpeg']);
        $json = json_decode(InvoiceParser::json($invoice, ['status' => 'accepted', 'invoice_number' => '1']), true);
        $this->assertSame(0, $json['rotation']);
        $json = json_decode(InvoiceParser::json($invoice, ['status' => 'accepted', 'rotation' => 270]), true);
        $this->assertSame(270, $json['rotation']);
        $this->assertArrayNotHasKey('rotation', InvoiceParser::clean(['status' => 'skipped', 'rotation' => 90]));
    }

    public function test_photo_has_no_caption_and_summary_is_escaped(): void
    {
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/p.png']]);
            }
            if (str_contains($request->url(), '/file/')) {
                return Http::response($this->png());
            }
            $sent[] = $request->isMultipart()
                ? collect($request->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']])->all()
                : $request->data();

            return Http::response(['ok' => true, 'result' => ['message_id' => 9]]);
        });
        $this->app->instance(ClaudeInvoiceReader::class, new class extends ClaudeInvoiceReader
        {
            public function __construct() {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                return ['text' => json_encode(['status' => 'accepted', 'invoice_number' => '0', 'supplier' => ['name' => '<a href="x">', 'inn' => '0'],
                    'items' => [], 'total_with_vat' => 0]), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => '-1001', 'target_chat_id' => '-1002']);
        $invoice = InvoiceMessage::create(['chat_id' => '-1001', 'message_id' => 1, 'file_id' => 'f', 'mime' => 'image/png']);
        app(InvoiceParser::class)->process($invoice);

        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertArrayNotHasKey('caption', $sent[0]);
        $this->assertStringStartsWith('🏢 <b>&lt;a href=&quot;x&quot;&gt;</b>', $sent[1]['text']);
        $this->assertStringNotContainsString('<a ', $sent[1]['text']);
        $this->assertStringContainsString('STIR: 0', $sent[1]['text'], '"0" is a value, not empty');
        $this->assertStringContainsString('Jami: 0 soʻm', $sent[1]['text']);
    }

    public function test_migrations_leave_image_message_id_and_drop_image_chat(): void
    {
        $this->assertFalse(Schema::hasColumn('invoice_parser_settings', 'image_chat_id'));
        $this->assertTrue(Schema::hasColumn('invoice_messages', 'image_message_id'));

        $drop = 'database/migrations/2026_11_17_200000_drop_image_chat_from_invoice_parser.php';
        Artisan::call('migrate:rollback', ['--path' => $drop, '--force' => true]);
        $this->assertTrue(Schema::hasColumn('invoice_parser_settings', 'image_chat_id'));
        Artisan::call('migrate', ['--path' => $drop, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('invoice_parser_settings', 'image_chat_id'));
    }
}
