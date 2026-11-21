<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Invoice parser settings: From IDs filter, the JSON chat next to the invoice chat, the key from .env. */
class InvoiceParserChatsTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const TARGET = '-1002222222222';

    private const JSON = '-1003333333333';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public int $reads = 0;

    public string $reply = '';

    /** chat_id => bool: sendMessage to that chat fails */
    public array $failChats = [];

    /** @var list<array{method: string, chat: string, text: string}> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.telegram.replies' => true, 'services.anthropic.key' => 'sk-ant-env-key']);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'json_chat_id' => self::JSON]);
        $this->reply = json_encode(['status' => 'accepted', 'invoice_number' => '15', 'supplier' => ['name' => 'Ёрлиқ <b>'], 'items' => [['name' => 'Label', 'quantity' => 2, 'price' => 5, 'amount' => 10]], 'total_with_vat' => 11.2]);

        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceParserChatsTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads++;
                $this->test->lastKey = $apiKey;

                return ['text' => $this->test->reply, 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });

        Http::fake(function (Request $request) {
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

    public ?string $lastKey = null;

    private function photo(int $messageId, array $from = ['id' => 555, 'first_name' => 'Ali'], array $extra = []): void
    {
        app(TelegramBot::class)->handle(['update_id' => $messageId, 'message' => [
            'message_id' => $messageId, 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'], 'from' => $from,
            'photo' => [['file_id' => "f{$messageId}", 'width' => 10]],
        ] + $extra]);
        $this->artisan('invoices:process')->assertSuccessful();
    }

    public function test_summary_goes_to_the_invoice_chat_and_json_to_the_json_chat(): void
    {
        $this->photo(1);

        $invoice = InvoiceMessage::sole();
        $this->assertSame('sent', $invoice->status);
        $this->assertSame('555', $invoice->sender_id);
        $this->assertSame(['sk-ant-env-key'], [$this->lastKey], 'the key from .env is used');
        $this->assertCount(2, $this->sent);
        [$summary, $json] = $this->sent;
        $this->assertSame([self::TARGET, self::JSON], [$summary['chat'], $json['chat']]);
        $this->assertStringStartsWith('🏢 <b>Ёрлиқ &lt;b&gt;</b>', $summary['text']);
        $this->assertStringStartsWith('<pre><code class="language-json">', $json['text']);
        $decoded = json_decode(html_entity_decode(strip_tags($json['text'])), true);
        $this->assertSame('15', $decoded['invoice_number']);
        $this->assertSame('Ёрлиқ <b>', $decoded['supplier']['name']);
        $this->assertSame(['chat_id' => self::SOURCE, 'message_id' => 1, 'from_id' => '555', 'from' => 'Ali'], $decoded['source']);
        $this->assertStringNotContainsString('<b>', str_replace(['<pre>', '</pre>'], '', preg_replace('#</?code[^>]*>#', '', $json['text'])), 'text from the image is escaped');
        $this->assertNotNull($invoice->sent_message_id);
        $this->assertNotNull($invoice->json_message_id);
    }

    public function test_only_json_chat_set(): void
    {
        InvoiceParserSetting::current()->update(['target_chat_id' => null]);
        $this->photo(1);

        $this->assertSame('sent', InvoiceMessage::sole()->status);
        $this->assertSame([self::JSON], array_column($this->sent, 'chat'));
    }

    public function test_from_ids_filter_reads_only_listed_senders(): void
    {
        InvoiceParserSetting::current()->update(['source_from_ids' => '777, 555']);
        $this->photo(1, ['id' => 999, 'first_name' => 'Stranger']);
        $this->photo(2, ['id' => 555, 'first_name' => 'Ali']);
        $this->photo(3, ['id' => 1087968824, 'first_name' => 'Group'], ['sender_chat' => ['id' => 777, 'type' => 'supergroup']]);

        $this->assertSame([2, 3], InvoiceMessage::orderBy('message_id')->pluck('message_id')->all(), 'stranger ignored');
        $this->assertSame(2, $this->reads);
        $this->assertSame('777', InvoiceMessage::where('message_id', 3)->value('sender_id'));
        $this->assertDatabaseCount('chat_conversations', 0); // the source chat is never recorded
    }

    public function test_empty_from_ids_reads_anyone(): void
    {
        $this->photo(1, ['id' => 999]);
        $this->photo(2, ['id' => 123]);
        $this->assertSame(2, $this->reads);
    }

    public function test_failed_json_post_is_retried_without_a_new_read_or_a_second_summary(): void
    {
        $this->failChats[self::JSON] = true;
        $this->photo(1);
        $invoice = InvoiceMessage::sole();
        $this->assertSame('failed', $invoice->status);
        $this->assertStringContainsString('JSON recipient', $invoice->error);
        $this->assertNotNull($invoice->sent_message_id);

        $this->failChats = [];
        $this->travel(6)->minutes();
        $this->artisan('invoices:process')->assertSuccessful();

        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame(1, $this->reads, 'no second paid read');
        $this->assertSame([self::TARGET, self::JSON], array_column($this->sent, 'chat'), 'summary posted once');
    }

    public function test_long_json_is_sent_as_a_file(): void
    {
        $items = array_map(fn ($i) => ['name' => str_repeat('Thermal label 58x40 ', 5).$i, 'quantity' => $i, 'price' => 1000, 'amount' => 1000 * $i], range(1, 60));
        $this->reply = json_encode(['status' => 'accepted', 'invoice_number' => '9', 'items' => $items]);
        $this->photo(1);

        $json = collect($this->sent)->firstWhere('chat', self::JSON);
        $this->assertSame('sendDocument', $json['method']);
        $this->assertCount(60, json_decode($json['text'], true)['items']);
        $this->assertSame('sent', InvoiceMessage::sole()->status);
    }

    public function test_skipped_photo_reaches_the_json_chat_only_with_notify_skipped(): void
    {
        $this->reply = json_encode(['status' => 'skipped', 'reason' => 'handwritten']);
        $this->photo(1);
        $this->assertSame([], $this->sent);

        InvoiceParserSetting::current()->update(['notify_skipped' => true]);
        $this->photo(2);
        // The invoice chat (accountants) never gets skips or errors: only the JSON recipient.
        $this->assertSame([self::JSON], array_column($this->sent, 'chat'));
        $this->assertStringContainsString('handwritten', html_entity_decode($this->sent[0]['text']));
    }

    public function test_old_settings_with_the_same_chat_send_no_errors_or_skips_there(): void
    {
        InvoiceParserSetting::current()->update(['json_chat_id' => self::TARGET, 'notify_skipped' => true]); // saved before the rule
        $this->reply = json_encode(['status' => 'skipped', 'reason' => 'handwritten']);
        $this->photo(1);
        $this->reply = 'not json';
        $this->photo(2);
        InvoiceMessage::where('message_id', 2)->update(['status' => 'failed', 'attempts' => InvoiceMessage::MAX_ATTEMPTS - 1, 'updated_at' => now()->subHour()]);
        $this->artisan('invoices:process'); // the last attempt: the error JSON would go out now
        $this->assertSame(InvoiceMessage::MAX_ATTEMPTS, InvoiceMessage::where('message_id', 2)->value('attempts'));
        $this->assertSame('failed', InvoiceMessage::where('message_id', 2)->value('status'));
        $this->assertSame([], $this->sent);
    }

    public function test_invoice_chat_cannot_be_the_json_recipient_or_the_alerts_chat(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['enabled' => '1', 'model' => 'claude-opus-5-5', 'source_chat_id' => self::SOURCE, 'target_chat_id' => self::TARGET, 'json_chat_id' => self::JSON];

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['json_chat_id' => self::TARGET] + $form)->assertSessionHasErrors('json_chat_id');

        config(['services.telegram.alert_chat_id' => '-1004444444444', 'services.telegram.chat_id' => '-1005555555555']);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['target_chat_id' => '-1004444444444'] + $form)->assertSessionHasErrors('target_chat_id');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['target_chat_id' => '-1005555555555'] + $form)->assertSessionHasErrors('target_chat_id');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form)->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['target_chat_id' => ''] + $form)->assertSessionHasNoErrors();
    }

    public function test_settings_form_saves_the_new_fields_and_shows_the_env_key_label(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['enabled' => '1', 'daily_limit' => 100, 'model' => 'claude-opus-5-5', 'source_chat_id' => self::SOURCE, 'target_chat_id' => '', 'json_chat_id' => self::JSON];

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['source_from_ids' => ' 555,777  555; -100123 '])->assertSessionHasNoErrors();
        $s = InvoiceParserSetting::current();
        $this->assertSame(['555, 777, -100123', null, self::JSON], [$s->source_from_ids, $s->target_chat_id, $s->json_chat_id]);

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['source_from_ids' => '@ali'])->assertSessionHasErrors('source_from_ids');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['json_chat_id' => self::SOURCE] + $form)->assertSessionHasErrors('json_chat_id');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['json_chat_id' => ''] + $form)->assertSessionHasErrors('enabled');

        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Set in .env (ANTHROPIC_API_KEY …-key)', $html);
        $this->assertStringNotContainsString('name="api_key"', $html, 'no key input');
        $this->assertStringNotContainsString('sk-ant-env-key', $html);
        foreach (['source_from_ids', 'target_chat_id', 'json_chat_id'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }

        config(['services.anthropic.key' => null]);
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->assertSee('Not set — add ANTHROPIC_API_KEY to .env');
    }
}
