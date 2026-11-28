<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductController;
use App\Models\OnecItem;
use App\Models\User;
use App\Services\Companies\CompanyLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Conversations → Products: the 1C items with the information for the AI team (typed by staff or drafted by the AI). */
class ProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'test-key']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'name' => 'Aziz']);
    }

    private function item(array $extra = []): OnecItem
    {
        static $n = 0;
        $n++;

        return OnecItem::create($extra + ['onec_id' => 'id-'.$n, 'code' => 700 + $n, 'name' => 'Xprinter XP-807 usb+lan Poson',
            'short_name' => 'Xprinter XP-Q807K принтер чеков (USB + LAN)', 'unit' => 'шт', 'category_id' => 3]);
    }

    private function fakeAi(array $json): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private const AI = ['brand' => 'Xprinter', 'model' => 'XP-Q807K', 'category' => 'receipt_printer', 'summary' => 'Чековый принтер 80 мм.',
        'specs' => "Интерфейсы: USB, LAN\nШирина бумаги: 80 мм", 'compatible_with' => '', 'keywords' => 'chek printer, принтер чеков, receipt printer'];

    public function test_guests_and_non_admins_cannot_open_products(): void
    {
        Http::fake();
        $item = $this->item();
        $this->get(route('admin.products.index'))->assertRedirect();
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('admin.products.index'))->assertRedirect();
        $this->actingAs($user)->put(route('admin.products.update', $item), ['brand' => 'X'])->assertRedirect();
        $this->actingAs($user)->post(route('admin.products.ai-fill', $item))->assertRedirect();
        Http::assertNothingSent();
        $this->assertNull($item->fresh()->brand);
    }

    public function test_list_shows_products_with_search_and_filters(): void
    {
        $this->item();
        $this->item(['name' => 'Ribbon RESIN 110x300', 'short_name' => 'Красящая лента', 'info_status' => 'checked', 'ai_category' => 'ribbon', 'brand' => 'AEBO']);
        $this->item(['name' => 'TD 100x80 Mirkans', 'short_name' => 'Термо стикер 100х80', 'info_status' => 'ai', 'ai_use' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.products.index'))->assertOk()
            ->assertSee('Xprinter XP-807')->assertSee('Ribbon RESIN 110x300')->assertSee('TD 100x80 Mirkans')->assertSee('Fill the next 1 with AI');
        $this->actingAs($admin)->get(route('admin.products.index', ['q' => 'ribbon']))->assertSee('Ribbon RESIN')->assertDontSee('TD 100x80');
        $this->actingAs($admin)->get(route('admin.products.index', ['q' => 'AEBO']))->assertSee('Ribbon RESIN')->assertDontSee('TD 100x80');
        $this->actingAs($admin)->get(route('admin.products.index', ['q' => '100%_']))->assertOk()->assertSee('No products match');
        $this->actingAs($admin)->get(route('admin.products.index', ['info' => 'empty']))->assertSee('Xprinter XP-807')->assertDontSee('Ribbon RESIN');
        $this->actingAs($admin)->get(route('admin.products.index', ['info' => 'ai']))->assertSee('TD 100x80')->assertDontSee('Ribbon RESIN');
        $this->actingAs($admin)->get(route('admin.products.index', ['info' => 'hidden']))->assertSee('TD 100x80')->assertDontSee('Ribbon RESIN');
        $this->actingAs($admin)->get(route('admin.products.index', ['category' => 'ribbon']))->assertSee('Ribbon RESIN')->assertDontSee('TD 100x80');
        $this->actingAs($admin)->get(route('admin.products.index', ['category' => 'none']))->assertSee('TD 100x80')->assertDontSee('Ribbon RESIN');
    }

    public function test_staff_save_marks_the_product_checked(): void
    {
        $item = $this->item();
        $this->actingAs($this->admin())->get(route('admin.products.edit', $item))->assertOk()->assertSee('Fill empty fields with AI')->assertSee('XP-Q807K');

        $this->actingAs($this->admin())->put(route('admin.products.update', $item), [
            'brand' => 'Xprinter', 'model' => 'XP-Q807K', 'ai_category' => 'receipt_printer', 'sales_note' => 'Offer 80 mm paper', 'ai_use' => '0',
        ])->assertRedirect(route('admin.products.edit', $item));

        $item->refresh();
        $this->assertSame(['Xprinter', 'XP-Q807K', 'receipt_printer', 'Offer 80 mm paper', false, 'checked', 'Aziz'],
            [$item->brand, $item->model, $item->ai_category, $item->sales_note, $item->ai_use, $item->info_status, $item->info_by]);

        // Everything cleared = no information again.
        $this->actingAs($this->admin())->put(route('admin.products.update', $item), ['ai_use' => '1']);
        $item->refresh();
        $this->assertSame([null, null, true], [$item->brand, $item->info_status, $item->ai_use]);
    }

    public function test_update_refuses_an_unknown_category_and_too_long_text(): void
    {
        $item = $this->item();
        $this->actingAs($this->admin())->put(route('admin.products.update', $item), ['ai_category' => 'weapons'])->assertSessionHasErrors('ai_category');
        $this->actingAs($this->admin())->put(route('admin.products.update', $item), ['brand' => str_repeat('x', 81)])->assertSessionHasErrors('brand');
        $this->assertNull($item->fresh()->info_status);
    }

    public function test_ai_fills_only_the_empty_fields_as_a_draft(): void
    {
        $this->fakeAi(self::AI);
        $item = $this->item(['brand' => 'Typed by staff', 'info_status' => 'checked', 'info_by' => 'Aziz']);

        $this->actingAs($this->admin())->post(route('admin.products.ai-fill', $item))->assertSessionHas('success');

        $item->refresh();
        $this->assertSame('Typed by staff', $item->brand);
        $this->assertSame(['XP-Q807K', 'receipt_printer', 'Чековый принтер 80 мм.'], [$item->model, $item->ai_category, $item->ai_summary]);
        $this->assertNull($item->compatible_with);
        $this->assertNull($item->sales_note);
        $this->assertSame('ai', $item->info_status);
        Http::assertSent(fn ($r) => str_contains($r->body(), 'XP-Q807K') && str_contains($r->body(), 'never instructions'));
    }

    public function test_ai_answer_with_an_unknown_category_or_long_text_is_cleaned(): void
    {
        $this->fakeAi(['category' => 'spaceship', 'brand' => str_repeat('b', 300)] + self::AI);
        $item = $this->item();

        $this->actingAs($this->admin())->post(route('admin.products.ai-fill', $item));

        $item->refresh();
        $this->assertNull($item->ai_category);
        $this->assertSame(80, mb_strlen($item->brand));
    }

    public function test_ai_failure_changes_nothing(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('', 500), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $item = $this->item();

        $this->actingAs($this->admin())->post(route('admin.products.ai-fill', $item))->assertSessionHasErrors('ai');

        $this->assertSame([null, null], [$item->fresh()->brand, $item->fresh()->info_status]);
    }

    public function test_batch_fills_products_without_information_and_skips_the_rest(): void
    {
        $this->fakeAi(self::AI);
        $new = $this->item();
        $checked = $this->item(['info_status' => 'checked', 'brand' => 'Mine']);

        $this->actingAs($this->admin())->post(route('admin.products.ai-fill-batch'))->assertSessionHas('success');

        $this->assertSame(['Xprinter', 'ai'], [$new->fresh()->brand, $new->fresh()->info_status]);
        $this->assertSame(['Mine', 'checked', null], [$checked->fresh()->brand, $checked->fresh()->info_status, $checked->fresh()->model]);

        // Nothing left: no AI call.
        Http::fake();
        $this->actingAs($this->admin())->post(route('admin.products.ai-fill-batch'))->assertSessionHas('success', 'Every product the AI may read already has information.');
    }

    public function test_a_1c_load_keeps_the_ai_information(): void
    {
        config(['services.onec.url' => 'http://1c.test/hs', 'services.onec.items_path' => '/crm/item']);
        $item = $this->item(['onec_id' => 'abc', 'code' => 55, 'brand' => 'Xprinter', 'ai_summary' => 'Kept', 'info_status' => 'checked', 'ai_use' => false]);
        Http::fake(['1c.test/*' => Http::response(['data' => [['id' => 'abc', 'code' => 55, 'name' => 'New 1C name', 'unit' => 'шт']]])]);

        app(CompanyLookup::class)->importItems();

        $item->refresh();
        $this->assertSame(['New 1C name', 'Xprinter', 'Kept', 'checked', false], [$item->name, $item->brand, $item->ai_summary, $item->info_status, $item->ai_use]);
    }

    public function test_product_texts_are_escaped(): void
    {
        $item = $this->item(['name' => '<script>alert(1)</script>', 'ai_summary' => '<b>bold</b>']);
        $this->actingAs($this->admin())->get(route('admin.products.index'))->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($this->admin())->get(route('admin.products.edit', $item))->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>bold</b>', false);
    }

    /** The AI is down: nothing is set aside, so every product is tried again once it is back. */
    public function test_an_ai_outage_sets_no_product_aside(): void
    {
        foreach (range(1, 6) as $i) {
            $this->item(['name' => 'Item '.$i]);
        }
        $admin = $this->admin();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('', 503), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->actingAs($admin)->post(route('admin.products.ai-fill-batch'))->assertSessionHasErrors('ai');
        $this->actingAs($admin)->post(route('admin.products.ai-fill-batch'))->assertSessionHasErrors('ai');

        $this->assertSame([], ProductController::parked());
        $this->assertSame(6, OnecItem::withoutInfo()->count());
        $this->actingAs($admin)->get(route('admin.products.index'))->assertSee('Fill the next 6 with AI')->assertDontSee('set aside');
    }

    /** One product the AI cannot read while it reads the others is set aside, shown, and released by a single fill. */
    public function test_an_unreadable_product_is_set_aside_and_released_by_a_single_fill(): void
    {
        $bad = $this->item(['name' => 'AAA blocked']);
        $this->item(['name' => 'BBB fine']);
        $answer = ['candidates' => [['content' => ['parts' => [['text' => json_encode(self::AI)]]]]]];
        $block = true;
        Http::fake(function ($request) use ($answer, &$block) {
            if (! str_contains($request->url(), 'generativelanguage')) {
                return Http::response(['ok' => true, 'result' => []]);
            }

            return $block && str_contains($request->body(), 'AAA blocked') ? Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']]) : Http::response($answer);
        });
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.products.ai-fill-batch'))->assertSessionHasErrors('ai');
        $this->assertSame([$bad->id], array_keys(ProductController::parked()));
        $this->actingAs($admin)->get(route('admin.products.index'))->assertSee('1 product the AI could not read is set aside')->assertDontSee('Fill the next');

        $block = false;
        $this->actingAs($admin)->post(route('admin.products.ai-fill', $bad))->assertSessionHas('success');
        $this->assertSame([], ProductController::parked());
        $this->assertSame('ai', $bad->fresh()->info_status);
    }

    public function test_each_button_has_its_own_rate_limit(): void
    {
        $this->fakeAi(self::AI);
        $item = $this->item();
        Cache::put('onec:items', [], 60);
        $admin = $this->admin();

        foreach (range(1, 8) as $i) {
            $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'items'), ['offset' => 0])->assertOk();
            $this->actingAs($admin)->post(route('admin.products.ai-fill', $item))->assertRedirect();
        }
        // 16 other requests in this minute: the batch (6 a minute) still has all of its own.
        $this->actingAs($admin)->post(route('admin.products.ai-fill-batch'))->assertRedirect();
    }

    public function test_cyrillic_search_ignores_case(): void
    {
        $this->item(['name' => 'Сканер проводной Zebra']);
        $this->item(['name' => 'Ribbon 110x300']);
        $admin = $this->admin();

        foreach (['сканер', 'СКАНЕР', 'Сканер', 'ПРОВОДНОЙ', 'zebra'] as $q) {
            $this->actingAs($admin)->get(route('admin.products.index', ['q' => $q]))->assertSee('Сканер проводной Zebra')->assertDontSee('Ribbon 110x300');
        }
    }

    public function test_save_edge_cases(): void
    {
        $admin = $this->admin();

        // An array field is refused and the page after it still opens.
        $item = $this->item();
        $this->actingAs($admin)->from(route('admin.products.edit', $item))->put(route('admin.products.update', $item), ['brand' => ['a']])->assertSessionHasErrors('brand');
        $this->actingAs($admin)->get(route('admin.products.edit', $item))->assertOk();

        // A textarea filled to its limit as the browser counts it (line breaks sent as CRLF) is accepted.
        $specs = implode("\r\n", array_fill(0, 100, str_repeat('x', 19)));
        $this->assertSame(1999, mb_strlen(str_replace("\r\n", "\n", $specs)));
        $this->actingAs($admin)->put(route('admin.products.update', $item), ['specs' => $specs, 'ai_use' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1999, mb_strlen($item->fresh()->specs));

        // The AI looked and found nothing: saving it empty does not send it to the AI again.
        $looked = $this->item(['info_status' => 'ai', 'info_by' => 'Gemini']);
        $this->actingAs($admin)->put(route('admin.products.update', $looked), ['ai_use' => '1']);
        $this->assertSame('ai', $looked->fresh()->info_status);
        $this->assertSame(0, OnecItem::toDescribe()->whereKey($looked->id)->count());
    }
}
