<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\InvoiceRead;
use App\Models\User;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\ClaudePricing;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Invoice parser: the cost of every Claude read, shown per photo and per period; the daily limit is optional. */
class InvoiceCostTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '-1001111111111';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public int $reads = 0;

    public array $usage = ['model' => 'claude-opus-5-5', 'input_tokens' => 2000, 'output_tokens' => 500, 'cache_write_tokens' => 1000, 'cache_read_tokens' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:TEST', 'services.anthropic.key' => 'sk-ant-env']);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => self::SOURCE, 'target_chat_id' => '-1002222222222', 'daily_limit' => null]);

        $this->app->instance(ClaudeInvoiceReader::class, new class($this) extends ClaudeInvoiceReader
        {
            public function __construct(private InvoiceCostTest $test) {}

            public function read(string $apiKey, string $model, string $system, string $bytes, string $mime): array
            {
                $this->test->reads++;

                return ['text' => json_encode(['status' => 'accepted', 'invoice_number' => '1', 'total_with_vat' => 10])] + $this->test->usage;
            }
        });
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/1.png']]),
            'api.telegram.org/file/*' => Http::response(base64_decode(self::PNG)),
            '*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]),
        ]);
    }

    private function photo(int $id): void
    {
        app(TelegramBot::class)->handle(['update_id' => $id, 'message' => [
            'message_id' => $id, 'chat' => ['id' => (int) self::SOURCE, 'type' => 'supergroup'], 'from' => ['id' => 5, 'first_name' => 'A'],
            'photo' => [['file_id' => "f{$id}", 'width' => 10]],
        ]]);
        $this->artisan('invoices:process')->assertSuccessful();
    }

    public function test_prices(): void
    {
        // Opus 5.5: $4 in, $20 out, $5 cache write, $0.20 cache read per million.
        $this->assertSame(0.023, ClaudePricing::cost('claude-opus-5-5', 2000, 500, 1000, 0));
        $this->assertSame(0.0002, ClaudePricing::cost('claude-opus-5-5', 0, 0, 0, 1000));
        $this->assertSame(0.0045, ClaudePricing::cost('claude-sonnet-5-5', 1000, 250));
        $this->assertSame(0.000225, ClaudePricing::cost('claude-haiku-5-5', 1000, 250));
        $this->assertSame(0.0225, ClaudePricing::cost('claude-opus-5-20261001', 2000, 500), 'dated id → Opus 5, not Opus 5.5');
        $this->assertSame(ClaudePricing::PRICES['claude-opus-5-5'], ClaudePricing::for('CLAUDE-OPUS-5-5'));
        $this->assertNull(ClaudePricing::cost('gpt-5', 1, 1));
        $this->assertSame('$0.0230', ClaudePricing::format(0.023));
        $this->assertSame('$12.50', ClaudePricing::format(12.5));
        $this->assertSame('—', ClaudePricing::format(null));
    }

    public function test_each_read_adds_its_cost_and_read_again_adds_more(): void
    {
        $this->photo(1);
        $invoice = InvoiceMessage::sole();
        $this->assertSame(1, $invoice->read_count);
        $this->assertEqualsWithDelta(0.023, $invoice->cost_usd, 1e-9);
        $this->assertSame(1000, $invoice->cache_write_tokens);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->usage = ['cache_write_tokens' => 0, 'cache_read_tokens' => 1000] + $this->usage;
        $this->actingAs($admin)->post(route('admin.invoice-messages.retry', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertSame(2, $invoice->read_count);
        $this->assertEqualsWithDelta(0.023 + 0.0182, $invoice->cost_usd, 1e-9);

        $this->actingAs($admin)->get(route('admin.invoice-messages.show', $invoice))->assertOk()->assertSee('$0.0412')->assertSee('for 2 paid reads');
    }

    public function test_messages_page_shows_totals_by_period(): void
    {
        $make = function ($at, $usd, $n = 1) {
            foreach (range(1, $n) as $i) {
                InvoiceRead::forceCreate(['model' => 'claude-opus-5-5', 'cost_usd' => $usd / $n, 'created_at' => $at]);
            }
        };
        $this->travelTo('2026-11-15 12:00');
        $make(now(), 0.02);
        $make(now()->subDays(3), 0.03, 2);
        $make('2026-10-20 10:00', 1.5);
        InvoiceMessage::create(['chat_id' => self::SOURCE, 'message_id' => 5, 'file_id' => 'f', 'status' => 'pending']);

        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.invoice-messages.index'))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('Today $0.0200 1 read · avg $0.0200', $text);
        $this->assertStringContainsString('This month $0.0500 3 reads', $text);
        $this->assertStringContainsString('Last month $1.50 1 read', $text);
        $this->assertStringContainsString('All time $1.55 4 reads', $text);
    }

    public function test_daily_limit_is_optional(): void
    {
        foreach (range(1, 3) as $i) {
            $this->photo($i);
        }
        $this->assertSame(3, $this->reads, 'no limit');

        InvoiceParserSetting::current()->update(['daily_limit' => 3]);
        $this->photo(4);
        $this->assertSame(3, $this->reads);
        $this->assertStringContainsString('Daily limit of 3', InvoiceMessage::where('message_id', 4)->value('error'));

        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['enabled' => '1', 'model' => 'claude-opus-5-5', 'source_chat_id' => self::SOURCE, 'target_chat_id' => '-1002222222222'];
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['daily_limit' => ''])->assertSessionHasNoErrors();
        $this->assertNull(InvoiceParserSetting::current()->dailyLimit());
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['daily_limit' => '0'])->assertSessionHasErrors('daily_limit');
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['daily_limit' => '20'])->assertSessionHasNoErrors();
        $this->assertSame(20, InvoiceParserSetting::current()->dailyLimit());
        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->assertSee('placeholder="No limit"', false);
    }

    public function test_a_read_again_next_month_counts_in_its_own_month_and_deleting_the_photo_keeps_the_spend(): void
    {
        $this->travelTo('2026-10-30 10:00');
        $this->photo(1);
        $invoice = InvoiceMessage::sole();

        $this->travelTo('2026-11-02 10:00');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.invoice-messages.retry', $invoice))->assertRedirect();
        $this->assertSame(2, $invoice->fresh()->read_count);
        $this->assertSame(2, InvoiceRead::count());

        $text = fn () => preg_replace('/\s+/', ' ', strip_tags($this->actingAs($admin)->get(route('admin.invoice-messages.index'))->getContent()));
        $this->assertStringContainsString('This month $0.0230 1 read', $text());
        $this->assertStringContainsString('Last month $0.0230 1 read', $text());

        $invoice->delete();
        $this->assertStringContainsString('All time $0.0460 2 reads', $text(), 'spend stays after the photo is deleted');
        $this->assertNull(InvoiceRead::first()->invoice_message_id);
    }

    public function test_daily_limit_counts_every_paid_read_including_retries(): void
    {
        InvoiceParserSetting::current()->update(['daily_limit' => 2]);
        InvoiceRead::forceCreate(['model' => 'claude-opus-5-5', 'cost_usd' => 0.01, 'created_at' => now()]);
        InvoiceRead::forceCreate(['model' => 'claude-opus-5-5', 'cost_usd' => 0.01, 'created_at' => now()]);
        InvoiceRead::forceCreate(['model' => 'claude-opus-5-5', 'cost_usd' => 0.01, 'created_at' => now()->subDay()]);

        $this->photo(1);
        $this->assertSame(0, $this->reads);
        $this->assertStringContainsString('Daily limit of 2', InvoiceMessage::sole()->error);
    }

    public function test_migration_rolls_back_with_no_limit_set_and_runs_again(): void
    {
        InvoiceParserSetting::current()->update(['daily_limit' => null]);
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_16_200000_add_costs_to_invoice_messages.php'])->assertSuccessful();
        $this->assertSame(100, (int) DB::table('invoice_parser_settings')->value('daily_limit'));
        $this->assertFalse(Schema::hasTable('invoice_reads'));
        $this->assertFalse(Schema::hasColumn('invoice_messages', 'cost_usd'));

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_11_16_200000_add_costs_to_invoice_messages.php'])->assertSuccessful();
        $this->assertNull(DB::table('invoice_parser_settings')->value('daily_limit'));
        $this->assertTrue(Schema::hasTable('invoice_reads'));
    }
}
