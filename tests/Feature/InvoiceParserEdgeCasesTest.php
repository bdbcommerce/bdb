<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\InvoiceParser;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA edge cases for the invoice parser (malformed updates, scheduler, summary, admin pages). */
class InvoiceParserEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const TARGET = '-1002222222222';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public array $reads = [];

    public array $replies = [];

    public bool $sendOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true]);
        InvoiceParserSetting::current()->update([
            'enabled' => true, 'api_key' => 'sk-ant-test-key', 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET,
        ]);
        $test = $this;
        $this->app->instance(ClaudeInvoiceReader::class, new class($test) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceParserEdgeCasesTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads[] = $mime;

                return ['text' => array_shift($this->test->replies) ?? '{"status":"skipped","reason":"x"}', 'model' => 'claude-opus-5-5', 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']]),
            'api.telegram.org/file/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'application/octet-stream']),
            'api.telegram.org/bot*/sendMessage' => fn () => $this->sendOk ? Http::response(['ok' => true, 'result' => ['message_id' => 77]]) : Http::response(['ok' => false], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function msg(array $over = []): array
    {
        return $over + [
            'message_id' => 10, 'date' => time(), 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'],
            'from' => ['id' => 5, 'first_name' => 'Ali'],
            'photo' => [['file_id' => 'big', 'width' => 1280]],
        ];
    }

    private function accepted(array $over = []): string
    {
        return json_encode($over + ['status' => 'accepted', 'invoice_number' => '1', 'total_with_vat' => 100], JSON_UNESCAPED_UNICODE);
    }

    public function test_malformed_updates_do_not_crash_or_capture(): void
    {
        $bot = app(TelegramBot::class);
        $bot->handle(['update_id' => 1, 'message' => $this->msg(['photo' => 'nope'])]);
        $bot->handle(['update_id' => 2, 'message' => $this->msg(['photo' => [1, 2, ['width' => 5]]])]);
        $bot->handle(['update_id' => 3, 'message' => $this->msg(['message_id' => null])]);
        $bot->handle(['update_id' => 4, 'message' => $this->msg(['chat' => ['id' => ['x']]])]);
        $bot->handle(['update_id' => 5, 'message' => $this->msg(['chat' => 'x'])]);
        $bot->handle(['update_id' => 6, 'message' => 'string']);
        $bot->handle(['update_id' => 7, 'message' => $this->msg(['photo' => null, 'document' => 'x'])]);
        $this->assertSame(0, InvoiceMessage::count());
        $this->assertSame(0, ChatConversation::count(), 'source-chat messages are not recorded');
    }

    public function test_chat_id_as_string_matches(): void
    {
        app(TelegramBot::class)->handle(['update_id' => 1, 'message' => $this->msg(['chat' => ['id' => self::SOURCE, 'type' => 'supergroup']])]);
        $this->assertSame(1, InvoiceMessage::count());
    }

    public function test_array_first_name_does_not_crash_capture(): void
    {
        app(TelegramBot::class)->handle(['update_id' => 1, 'message' => $this->msg(['from' => ['id' => 5, 'first_name' => ['x'], 'username' => ['y']]])]);
        $this->assertSame(1, InvoiceMessage::count());
    }

    public function test_edited_message_in_source_is_ignored_and_not_recorded(): void
    {
        app(TelegramBot::class)->handle(['update_id' => 1, 'edited_message' => $this->msg()]);
        $this->assertSame(0, InvoiceMessage::count());
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_album_photos_are_each_captured(): void
    {
        $bot = app(TelegramBot::class);
        $bot->handle(['update_id' => 1, 'message' => $this->msg(['message_id' => 1, 'media_group_id' => 'g1', 'caption' => 'page 1'])]);
        $bot->handle(['update_id' => 2, 'message' => $this->msg(['message_id' => 2, 'media_group_id' => 'g1'])]);
        $this->assertSame(2, InvoiceMessage::count());
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertCount(2, $this->reads);
    }

    public function test_private_source_chat_swallows_user_messages(): void
    {
        InvoiceParserSetting::current()->update(['source_chat_id' => '555']);
        $bot = app(TelegramBot::class);
        $bot->handle(['update_id' => 1, 'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'first_name' => 'Acc'], 'text' => '/start']]);
        $bot->handle(['update_id' => 2, 'message' => $this->msg(['message_id' => 2, 'chat' => ['id' => 555, 'type' => 'private']])]);
        $this->assertSame(1, InvoiceMessage::count());
        $this->assertSame(0, TelegramUser::count(), 'the private source chat is not a bot customer');
    }

    public function test_stuck_processing_at_max_attempts_is_due_forever(): void
    {
        $row = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 1, 'file_id' => 'f', 'status' => 'processing', 'attempts' => InvoiceMessage::MAX_ATTEMPTS]);
        DB::table('invoice_messages')->update(['updated_at' => now()->subMinutes(30)]);

        $this->artisan('invoices:process')->assertSuccessful();
        $row->refresh();
        // Report what happens: it should become 'failed' (so it leaves the due list), not stay 'processing'.
        $this->assertNotSame('processing', $row->status, 'a crashed last attempt stays processing and is due every minute');
    }

    public function test_stuck_rows_at_max_attempts_do_not_starve_the_scheduler(): void
    {
        foreach (range(1, 20) as $i) {
            InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => $i, 'file_id' => 'f', 'status' => 'processing', 'attempts' => InvoiceMessage::MAX_ATTEMPTS]);
        }
        $waiting = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 99, 'file_id' => 'f', 'status' => 'failed', 'attempts' => 1]);
        DB::table('invoice_messages')->update(['updated_at' => now()->subMinutes(30)]);

        $this->artisan('invoices:process')->assertSuccessful();
        $this->artisan('invoices:process')->assertSuccessful(); // the first run marks the stuck rows failed
        $this->assertSame('skipped', $waiting->fresh()->status, 'the retryable row is read despite 20 stuck rows ahead of it');
    }

    public function test_due_scope_groups_or_conditions(): void
    {
        InvoiceMessage::create(['chat_id' => 'A', 'message_id' => 1, 'file_id' => 'f', 'status' => 'failed', 'attempts' => 1]);
        InvoiceMessage::create(['chat_id' => 'B', 'message_id' => 2, 'file_id' => 'f', 'status' => 'pending']);
        InvoiceMessage::create(['chat_id' => 'B', 'message_id' => 3, 'file_id' => 'f', 'status' => 'sent']);
        InvoiceMessage::create(['chat_id' => 'B', 'message_id' => 4, 'file_id' => 'f', 'status' => 'failed', 'attempts' => 3]);
        $this->travel(11)->minutes();

        $this->assertSame([1, 2], InvoiceMessage::due()->orderBy('id')->pluck('message_id')->all());
        $this->assertSame([2], InvoiceMessage::where('chat_id', 'B')->due()->pluck('message_id')->all());
        $this->assertSame([2], InvoiceMessage::due()->where('chat_id', 'B')->pluck('message_id')->all());
    }

    public function test_retry_while_processing_is_refused(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $row = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 1, 'file_id' => 'f', 'status' => 'processing', 'attempts' => 1]);
        $this->actingAs($admin)->post(route('admin.invoice-messages.retry', $row))->assertStatus(409);
        $this->assertSame('processing', $row->fresh()->status);
    }

    public function test_json_with_extra_text(): void
    {
        $this->replies = ['Here is the result: '.$this->accepted().' Hope it helps.'];
        app(TelegramBot::class)->handle(['update_id' => 1, 'message' => $this->msg()]);
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertSame('sent', InvoiceMessage::sole()->status);
    }

    public function test_post_failure_keeps_result_and_is_retryable(): void
    {
        $this->sendOk = false;
        $this->replies = [$this->accepted()];
        app(TelegramBot::class)->handle(['update_id' => 1, 'message' => $this->msg()]);
        $this->artisan('invoices:process')->assertSuccessful();
        $row = InvoiceMessage::sole();
        $this->assertSame(['failed', 1, '1'], [$row->status, $row->attempts, $row->result['invoice_number'] ?? null]);
    }

    public function test_summary_number_edge_cases(): void
    {
        $s = InvoiceParser::summary([
            'status' => 'accepted', 'invoice_number' => null, 'invoice_date' => 'garbage <x>',
            'supplier' => 'string supplier', 'buyer' => null,
            'items' => [
                ['name' => 'Ўзбек товар', 'quantity' => '3', 'price' => '-1000.5', 'amount' => 1e15],
                ['name' => ['arr'], 'quantity' => null, 'price' => 'n/a', 'amount' => true],
                'not-an-array',
            ],
            'total_with_vat' => '1502820.00', 'total_vat' => 'x', 'warning' => 'a & b',
        ]);
        $this->assertStringContainsString('Hisob-faktura № —</b> — garbage &lt;x&gt;', $s);
        $this->assertStringContainsString('1. Ўзбек товар — 3 × -1 000,50 = 1 000 000 000 000 000', $s);
        $this->assertStringContainsString('Jami: 1 502 820 soʻm', $s);
        $this->assertStringContainsString('a &amp; b', $s);
        $this->assertStringNotContainsString('QQS:', $s);
    }

    public function test_summary_stays_within_limit_with_huge_head_or_warning(): void
    {
        $s = InvoiceParser::summary(['status' => 'accepted', 'supplier' => ['name' => str_repeat('A', 3000)], 'warning' => str_repeat('W', 3000), 'items' => []]);
        $this->assertLessThanOrEqual(4096, mb_strlen($s), 'header/footer are not limited');
    }

    public function test_index_and_show_with_non_scalar_result_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $row = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 1, 'file_id' => 'f', 'status' => 'sent',
            'result' => ['status' => 'accepted', 'invoice_number' => ['125'], 'supplier' => ['name' => ['x']], 'items' => [['name' => ['y'], 'vat_rate' => [12]]]]]);
        $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.invoice-messages.show', $row))->assertOk();
    }

    public function test_index_and_show_with_cyrillic_and_xss(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $row = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 1, 'file_id' => 'f', 'status' => 'sent', 'sender' => '<script>s</script>', 'caption' => '<img onerror=1>',
            'result' => ['status' => 'accepted', 'invoice_number' => '<b>7</b>', 'supplier' => ['name' => 'ООО «Тест» <script>'], 'items' => [['name' => 'Ёрлиқ', 'quantity' => 1]]]]);
        $html = $this->actingAs($admin)->get(route('admin.invoice-messages.show', $row))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>s</script>', $html);
        $this->assertStringNotContainsString('<img onerror', $html);
        $this->assertStringContainsString('ООО «Тест» &lt;script&gt;', $html);
        $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk()->assertDontSee('<script>s</script>', false);
    }

    public function test_settings_page_survives_undecryptable_key(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        DB::table('invoice_parser_settings')->where('id', 1)->update(['api_key' => 'eyJpdiI6ImJhZCJ9']);
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk();
    }

    public function test_new_api_key_is_not_flashed_to_the_session_on_errors(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['enabled' => '1', 'daily_limit' => 100, 'model' => 'claude-opus-5-5', 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::SOURCE, 'api_key' => 'sk-ant-new-secret'];
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form)->assertSessionHasErrors('target_chat_id');
        $this->assertNull(session()->getOldInput('api_key'), 'validation error flashes the new key into the session');

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['target_chat_id' => null] + $form)->assertSessionHasErrors();
        $this->assertNull(session()->getOldInput('api_key'), 'withInput() flashes the new key into the session');
    }

    public function test_menu_badge_counts_failed(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        InvoiceMessage::create(['chat_id' => 'A', 'message_id' => 1, 'file_id' => 'f', 'status' => 'failed']);
        InvoiceMessage::create(['chat_id' => 'A', 'message_id' => 2, 'file_id' => 'f', 'status' => 'failed']);
        $html = $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>\s*2\s*<span class="sr-only">failed invoice reads<\/span>/', $html);
    }

    public function test_custom_prompt_is_stored_and_used(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['enabled' => '1', 'daily_limit' => 100, 'model' => 'claude-haiku-5-5', 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET];
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['prompt' => '   '])->assertSessionHasNoErrors();
        $this->assertNull(InvoiceParserSetting::current()->prompt);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['prompt' => 'Custom Ўзбек'])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('Custom Ўзбек', InvoiceParserSetting::current()->systemPrompt());
        $r = $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['source_chat_id' => '-100 123'] + $form);
        $r->assertSessionHasErrors('source_chat_id');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['api_key' => 'has space'] + $form)->assertSessionHasErrors('api_key');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['source_chat_id' => '-100'.str_repeat('1', 70)] + $form)->assertSessionHasErrors('source_chat_id');
    }

    public function test_switched_on_but_target_missing_records_failure_not_crash(): void
    {
        InvoiceParserSetting::current()->update(['target_chat_id' => null]);
        app(TelegramBot::class)->handle(['update_id' => 1, 'message' => $this->msg()]);
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertSame('failed', InvoiceMessage::sole()->status);
        $this->assertCount(0, $this->reads);
    }
}
