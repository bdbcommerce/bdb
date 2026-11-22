<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\InvoiceRead;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\ClaudePricing;
use App\Services\Invoices\InvoiceParser;
use App\Services\Invoices\InvoiceReadException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: invoice read costs across retry / failure paths, period boundaries, rendering and formatting. */
class InvoiceCostQaTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** Queued replies: string = Claude text, InvoiceReadException = thrown. */
    public array $replies = [];

    public int $reads = 0;

    public string $model = 'claude-opus-5-5';

    public bool $postOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.anthropic.key' => 'sk-ant-env']);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => self::SOURCE, 'target_chat_id' => '-1002222222222', 'daily_limit' => null]);

        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceCostQaTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads++;
                $r = array_shift($this->test->replies);
                if ($r instanceof InvoiceReadException) {
                    throw $r;
                }

                // 2000 in + 500 out on Opus 5.5 = $0.018
                return ['text' => $r, 'model' => $this->test->model, 'input_tokens' => 2000, 'output_tokens' => 500, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0];
            }
        });
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/1.png']]);
            }
            if (str_contains($url, '/file/')) {
                return Http::response(base64_decode(self::PNG));
            }
            if (str_contains($url, '/sendMessage') && ! $this->postOk) {
                return Http::response(['ok' => false, 'description' => 'Forbidden'], 403);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 9]]);
        });
    }

    private function invoice(): InvoiceMessage
    {
        return InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => random_int(1, 1e9), 'file_id' => 'f1', 'mime' => 'image/jpeg', 'status' => 'pending']);
    }

    private function process(InvoiceMessage $i): InvoiceMessage
    {
        app(InvoiceParser::class)->process($i);

        return $i->refresh();
    }

    private function accepted(): string
    {
        return json_encode(['status' => 'accepted', 'invoice_number' => '1', 'total_with_vat' => 10]);
    }

    public function test_bad_json_then_retry_counts_two_reads_once_each(): void
    {
        $this->replies = ['not json at all', $this->accepted()];
        $i = $this->process($this->invoice());
        $this->assertSame('failed', $i->status);
        $this->assertSame(1, $i->read_count);
        $this->assertEqualsWithDelta(0.018, $i->cost_usd, 1e-9);

        $i = $this->process($i);
        $this->assertSame('sent', $i->status);
        $this->assertSame(2, $this->reads);
        $this->assertSame(2, $i->read_count);
        $this->assertEqualsWithDelta(0.036, $i->cost_usd, 1e-9);
    }

    public function test_post_failure_then_repost_does_not_add_cost(): void
    {
        $this->replies = [$this->accepted()];
        $this->postOk = false;
        $i = $this->process($this->invoice());
        $this->assertSame('failed', $i->status);
        $this->assertSame(1, $i->read_count);
        $this->assertEqualsWithDelta(0.018, $i->cost_usd, 1e-9);

        $this->postOk = true;
        $i = $this->process($i);
        $this->assertSame('sent', $i->status);
        $this->assertSame(1, $this->reads, 're-post without a new read');
        $this->assertSame(1, $i->read_count);
        $this->assertEqualsWithDelta(0.018, $i->cost_usd, 1e-9);
    }

    public function test_read_exception_adds_no_cost_and_skipped_counts_once(): void
    {
        $this->replies = [new InvoiceReadException('Claude API error 529: overloaded', true), json_encode(['status' => 'skipped', 'reason' => 'x'])];
        $i = $this->process($this->invoice());
        $this->assertSame(0, (int) $i->read_count);
        $this->assertEqualsWithDelta(0, $i->cost_usd, 1e-9);

        $i = $this->process($i);
        $this->assertSame('skipped', $i->status);
        $this->assertSame(1, $i->read_count);
        $this->assertEqualsWithDelta(0.018, $i->cost_usd, 1e-9);
    }

    public function test_unknown_model_counts_the_read_at_zero_cost_and_pages_render(): void
    {
        $this->model = 'claude-mystery-9';
        $this->replies = [$this->accepted()];
        $i = $this->process($this->invoice());
        $this->assertSame(1, $i->read_count);
        $this->assertEqualsWithDelta(0, $i->cost_usd, 1e-9);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.invoice-messages.show', $i))->assertOk()
            ->assertSee('price of this model unknown')->assertSee('for 1 paid read');
        $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk();
    }

    public function test_empty_index_and_zero_read_rows_render(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $text = preg_replace('/\s+/', ' ', strip_tags($this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk()->getContent()));
        $this->assertStringContainsString('Today $0.00 0 reads', $text);
        $this->assertStringContainsString('All time $0.00 0 reads', $text);
        $this->assertStringNotContainsString('avg', $text);

        $i = $this->invoice();
        $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.invoice-messages.show', $i))->assertOk()->assertDontSee('paid read');
    }

    public function test_access_control(): void
    {
        $this->get(route('admin.invoice-messages.index'))->assertRedirect();
        $user = User::factory()->create(['is_admin' => false]);
        $r = $this->actingAs($user)->get(route('admin.invoice-messages.index'));
        $this->assertNotSame(200, $r->getStatusCode());
    }

    public function test_month_boundaries(): void
    {
        $make = fn ($at, $usd) => InvoiceRead::forceCreate(['model' => 'claude-opus-5-5', 'cost_usd' => $usd, 'created_at' => $at]);
        $this->travelTo('2026-12-01 00:00:00');
        $make('2026-11-30 23:59:59', 1.0);   // last month
        $make('2026-12-01 00:00:00', 2.0);   // today + this month
        $make('2026-11-01 00:00:00', 4.0);   // last month, first second
        $make('2026-10-31 23:59:59', 8.0);   // older

        $admin = User::factory()->create(['is_admin' => true]);
        $text = preg_replace('/\s+/', ' ', strip_tags($this->actingAs($admin)->get(route('admin.invoice-messages.index'))->getContent()));
        $this->assertStringContainsString('Today $2.00 1 read', $text);
        $this->assertStringContainsString('This month $2.00 1 read', $text);
        $this->assertStringContainsString('Last month $5.00 2 reads', $text);
        $this->assertStringContainsString('All time $15.00 4 reads', $text);

        // 31 March: last month must be February (no overflow into March).
        InvoiceRead::query()->delete();
        $this->travelTo('2026-03-31 12:00:00');
        $make('2026-02-15 10:00:00', 3.0);
        $make('2026-03-01 00:00:00', 1.0);
        $text = preg_replace('/\s+/', ' ', strip_tags($this->actingAs($admin)->get(route('admin.invoice-messages.index'))->getContent()));
        $this->assertStringContainsString('Last month $3.00 1 read', $text);
        $this->assertStringContainsString('This month $1.00 1 read', $text);
    }

    public function test_format_edges(): void
    {
        $this->assertSame('$0.00', ClaudePricing::format(0.0));
        $this->assertSame('$1.00', ClaudePricing::format(1.0));
        $this->assertSame('$1 234.50', ClaudePricing::format(1234.5));
        // Tiny real costs and values that round up to $1:
        $this->assertSame('<$0.0001', ClaudePricing::format(0.00004));
        $this->assertSame('$1.00', ClaudePricing::format(0.99996));
    }

    public function test_settings_limit_validation_edges(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['enabled' => '1', 'model' => 'claude-opus-5-5', 'source_chat_id' => self::SOURCE, 'target_chat_id' => '-1002222222222'];
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['daily_limit' => '5001'])->assertSessionHasErrors('daily_limit');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['daily_limit' => 'abc'])->assertSessionHasErrors('daily_limit');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form)->assertSessionHasNoErrors(); // field absent
        $this->assertNull(InvoiceParserSetting::current()->dailyLimit());
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['daily_limit' => '5000'])->assertSessionHasNoErrors();
        $this->assertSame(5000, InvoiceParserSetting::current()->dailyLimit());
    }
}
