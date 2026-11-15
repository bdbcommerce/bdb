<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\InvoiceParser;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: From IDs, JSON chat and the .env key — edge cases beyond InvoiceParserChatsTest. */
class InvoiceParserChatsQaTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const TARGET = '-1002222222222';

    private const JSON = '-1003333333333';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public int $reads = 0;

    public string $reply = '';

    public array $failChats = [];

    public array $sent = [];

    public int $httpCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true, 'services.anthropic.key' => 'sk-ant-env-key-WXYZ']);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'json_chat_id' => self::JSON]);
        $this->reply = json_encode(['status' => 'accepted', 'invoice_number' => '7', 'items' => [['name' => 'Label', 'quantity' => 1, 'price' => 5, 'amount' => 5]]]);

        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceParserChatsQaTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads++;

                return ['text' => $this->test->reply, 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });

        Http::fake(function (Request $request) {
            $this->httpCalls++;
            $url = $request->url();
            if (str_contains($url, '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'p/1.png']]);
            }
            if (str_contains($url, '/file/')) {
                return Http::response(base64_decode(self::PNG));
            }
            foreach (['sendMessage', 'sendDocument'] as $method) {
                if (str_contains($url, '/'.$method)) {
                    $data = $request->isMultipart()
                        ? collect($request->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']])->all()
                        : $request->data();
                    $chat = (string) ($data['chat_id'] ?? '');
                    if (! empty($this->failChats[$chat])) {
                        return Http::response(['ok' => false], 400);
                    }
                    $this->sent[] = ['method' => $method, 'chat' => $chat, 'text' => (string) ($data['text'] ?? $data['document'] ?? '')];

                    return Http::response(['ok' => true, 'result' => ['message_id' => 100 + count($this->sent)]]);
                }
            }

            return Http::response(['ok' => true, 'result' => []]);
        });
    }

    private function photo(int $messageId, array $from = ['id' => 555, 'first_name' => 'Ali'], array $extra = []): void
    {
        app(TelegramBot::class)->handle(['update_id' => $messageId, 'message' => [
            'message_id' => $messageId, 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'], 'from' => $from,
            'photo' => [['file_id' => "f{$messageId}", 'width' => 10]],
        ] + $extra]);
        $this->artisan('invoices:process')->assertSuccessful();
    }

    private function form(array $over = []): array
    {
        return $over + ['enabled' => '1', 'daily_limit' => 100, 'model' => 'claude-opus-5-5', 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'json_chat_id' => self::JSON];
    }

    public function test_stranger_photo_makes_no_http_call_and_no_row(): void
    {
        InvoiceParserSetting::current()->update(['source_from_ids' => '555']);
        $this->photo(1, ['id' => 999, 'first_name' => 'Stranger']);
        $this->photo(2, ['id' => 999], ['sender_chat' => ['id' => -100999, 'type' => 'channel']]);

        $this->assertSame(0, InvoiceMessage::count());
        $this->assertSame(0, $this->httpCalls);
        $this->assertDatabaseCount('chat_conversations', 0);
    }

    public function test_negative_sender_chat_id_and_string_ids_match(): void
    {
        InvoiceParserSetting::current()->update(['source_from_ids' => '-1004444444444']);
        $this->photo(1, ['id' => 1087968824, 'first_name' => 'Group'], ['sender_chat' => ['id' => -1004444444444, 'type' => 'supergroup']]);
        $this->assertSame('-1004444444444', InvoiceMessage::sole()->sender_id);

        InvoiceParserSetting::current()->update(['source_from_ids' => '555']);
        $this->photo(2, ['id' => '555', 'first_name' => 'StrId']);
        $this->assertSame('555', InvoiceMessage::where('message_id', 2)->value('sender_id'));
    }

    public function test_from_ids_validation_edge_cases_and_old_input(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['source_from_ids' => " ;, \n123;;-456 ,\t789,123 "]))->assertSessionHasNoErrors();
        $this->assertSame('123, -456, 789', InvoiceParserSetting::current()->source_from_ids);

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['source_from_ids' => ' , ; ']))->assertSessionHasNoErrors();
        $this->assertNull(InvoiceParserSetting::current()->source_from_ids, 'separators only = anyone');

        foreach (['12a', '--5', '1.5', '0x10', '5-', '@ali'] as $bad) {
            $this->actingAs($admin)->from(route('admin.invoice-parser.edit'))
                ->put(route('admin.invoice-parser.update'), $this->form(['source_from_ids' => $bad]))
                ->assertSessionHasErrors(['source_from_ids' => 'From IDs are numbers separated by commas, e.g. 123456789, 987654321.']);
        }
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['source_from_ids' => str_repeat('1, ', 400)]))->assertSessionHasErrors('source_from_ids');

        // Old input is kept after a validation error.
        $this->actingAs($admin)->from(route('admin.invoice-parser.edit'))->put(route('admin.invoice-parser.update'), $this->form(['source_from_ids' => '@ali', 'json_chat_id' => '-1009']));
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->assertSee('value="@ali"', false)->assertSee('value="-1009"', false)
            ->assertSee('From IDs are numbers separated by commas');
    }

    /** QA defect: IDs glued by "-" or longer than 20 digits pass validation and are saved as one ID that never matches. */
    public function test_from_ids_without_separator_or_too_long_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        foreach (['1-2', '123456789-987654321', str_repeat('1', 21)] as $bad) {
            $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['source_from_ids' => $bad]))
                ->assertSessionHasErrors('source_from_ids');
        }
    }

    /** QA defect: "Read again" on a sent invoice promises a new summary, but the remembered message IDs make post() send nothing. */
    public function test_read_again_on_a_sent_invoice_posts_again(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->photo(1);
        $invoice = InvoiceMessage::sole();
        $this->assertSame('sent', $invoice->status);
        $this->assertCount(2, $this->sent);

        $this->sent = [];
        $this->actingAs($admin)->post(route('admin.invoice-messages.retry', $invoice))->assertRedirect();

        $this->assertSame(2, $this->reads, 'read again');
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame([self::TARGET, self::JSON], array_column($this->sent, 'chat'), 'a new summary and JSON are posted');
    }

    public function test_chat_equality_rules(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['json_chat_id' => self::SOURCE]))
            ->assertSessionHasErrors(['json_chat_id' => 'The JSON chat must differ from the source chat.']);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['json_chat_id' => 'abc']))
            ->assertSessionHasErrors(['json_chat_id' => 'A chat ID is a number, e.g. -1001234567890 for a group.']);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['json_chat_id' => self::TARGET]))->assertSessionHasNoErrors();
        $this->assertSame(self::TARGET, InvoiceParserSetting::current()->json_chat_id);

        // Same chat for both: two messages there.
        $this->photo(1);
        $this->assertSame([self::TARGET, self::TARGET], array_column($this->sent, 'chat'));
        $this->assertSame('sent', InvoiceMessage::sole()->status);

        // Disabled with nothing set is allowed; enabled with neither result chat is not.
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['enabled' => '0', 'target_chat_id' => '', 'json_chat_id' => '']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['target_chat_id' => '', 'json_chat_id' => '']))->assertSessionHasErrors('enabled');
    }

    public function test_json_block_cannot_be_broken_out_of(): void
    {
        $evil = '</code></pre><a href="https://evil.example">pay here</a> & "q" \'s @admin';
        $this->reply = json_encode(['status' => 'accepted', 'invoice_number' => $evil, 'supplier' => ['name' => $evil], 'warning' => $evil,
            'items' => [['name' => $evil, 'unit' => '<i>', 'quantity' => 1, 'price' => 1, 'amount' => 1]]]);
        $this->photo(1, ['id' => 555, 'first_name' => '<b>Ali</b>', 'last_name' => '</code>']);

        $json = collect($this->sent)->firstWhere('chat', self::JSON)['text'];
        $this->assertStringStartsWith('<pre><code class="language-json">', $json);
        $this->assertStringEndsWith('</code></pre>', $json);
        $inner = substr($json, strlen('<pre><code class="language-json">'), -strlen('</code></pre>'));
        $this->assertStringNotContainsString('<', $inner);
        $this->assertStringNotContainsString('>', $inner);
        $decoded = json_decode(html_entity_decode($inner, ENT_QUOTES), true);
        $this->assertSame($evil, $decoded['supplier']['name']);
        $this->assertSame('<b>Ali</b> </code>', $decoded['source']['from']);
    }

    public function test_failed_summary_is_retried_without_resending_json_and_both_failing(): void
    {
        $this->failChats[self::TARGET] = true;
        $this->photo(1);
        $invoice = InvoiceMessage::sole();
        $this->assertSame('failed', $invoice->status);
        $this->assertStringContainsString('invoice chat', $invoice->error);
        $this->assertNull($invoice->sent_message_id);
        $this->assertNotNull($invoice->json_message_id);

        $this->failChats = [];
        $this->travel(6)->minutes();
        $this->artisan('invoices:process')->assertSuccessful();
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame(1, $this->reads);
        $this->assertSame([self::JSON, self::TARGET], array_column($this->sent, 'chat'));

        // Both fail
        $this->sent = [];
        $this->failChats = [self::TARGET => true, self::JSON => true];
        $this->photo(2);
        $second = InvoiceMessage::where('message_id', 2)->sole();
        $this->assertSame('failed', $second->status);
        $this->assertStringContainsString('invoice chat and the JSON chat', $second->error);
        $this->assertSame('accepted', $second->result['status']);
    }

    public function test_settings_page_key_labels_and_undecryptable_key(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        InvoiceParserSetting::current()->update(['api_key' => 'sk-ant-stored-ABCD']);

        // .env wins and the stored key is never in the HTML.
        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('ANTHROPIC_API_KEY …WXYZ', $html);
        $this->assertStringNotContainsString('sk-ant-env-key', $html);
        $this->assertStringNotContainsString('sk-ant-stored', $html);
        $this->assertStringContainsString('name="remove_key"', $html);

        // No .env key: the stored key is the fallback.
        config(['services.anthropic.key' => null]);
        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('using a key saved here earlier (…ABCD)', $html);
        $this->assertStringNotContainsString('sk-ant-stored', $html);
        $this->assertSame('sk-ant-stored-ABCD', InvoiceParserSetting::current()->apiKey());

        // Undecryptable stored key (APP_KEY changed).
        DB::table('invoice_parser_settings')->where('id', 1)->update(['api_key' => 'not-a-valid-payload']);
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->assertSee('(cannot be decrypted)');
        $this->assertNull(InvoiceParserSetting::current()->apiKey());
        // Saving the form keeps working, and the enable check refuses without a usable key.
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['enabled' => '0']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form())->assertSessionHasErrors('enabled');
        // Remove the stored key.
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['enabled' => '0', 'remove_key' => '1']))->assertSessionHasNoErrors();
        $this->assertFalse(InvoiceParserSetting::current()->hasStoredKey());
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->assertSee('Not set — add ANTHROPIC_API_KEY to .env')->assertDontSee('name="remove_key"', false);

        // A posted api_key is ignored.
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $this->form(['enabled' => '0', 'api_key' => 'sk-ant-posted']))->assertSessionHasNoErrors();
        $this->assertFalse(InvoiceParserSetting::current()->hasStoredKey());
    }

    public function test_access_control(): void
    {
        $this->get(route('admin.invoice-parser.edit'))->assertRedirect();
        $this->put(route('admin.invoice-parser.update'), $this->form())->assertRedirect();
        $user = User::factory()->create(['is_admin' => false]);
        $r = $this->actingAs($user)->put(route('admin.invoice-parser.update'), $this->form(['source_from_ids' => '1']));
        $this->assertContains($r->status(), [302, 403]);
        $this->assertNull(InvoiceParserSetting::current()->source_from_ids);
    }

    public function test_ai_model_anthropic_fallback_prefers_env_then_stored(): void
    {
        InvoiceParserSetting::current()->update(['api_key' => 'sk-ant-stored-ABCD']);
        $model = new AiModel(['name' => 'C', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5']);
        $this->assertSame('sk-ant-env-key-WXYZ', $model->key());

        config(['services.anthropic.key' => '']);
        $this->assertSame('sk-ant-stored-ABCD', $model->key());

        $own = new AiModel(['name' => 'C', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'sk-own']);
        $this->assertSame('sk-own', $own->key());
    }

    public function test_messages_pages_show_from_id_and_escape_sender(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->photo(1, ['id' => 555, 'first_name' => '<script>x</script>']);
        $invoice = InvoiceMessage::sole();
        $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk()->assertSee('From ID 555')->assertDontSee('<script>x</script>', false);
        $this->actingAs($admin)->get(route('admin.invoice-messages.show', $invoice))->assertOk()->assertSee('From ID')->assertSee('555')->assertDontSee('<script>x</script>', false);
    }

    public function test_json_helper_contains_source_and_unicode(): void
    {
        $invoice = InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 5, 'file_id' => 'f', 'sender' => 'Oʻlmas Ёрлиқ', 'sender_id' => '555']);
        $json = json_decode(InvoiceParser::json($invoice, ['status' => 'accepted', 'invoice_number' => 'Ҳ-1']), true);
        $this->assertSame('Oʻlmas Ёрлиқ', $json['source']['from']);
        $this->assertSame('Ҳ-1', $json['invoice_number']);
        $this->assertStringContainsString('Ёрлиқ', InvoiceParser::json($invoice, ['status' => 'accepted']), 'unicode kept unescaped');
    }
}
