<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\McpSetting;
use App\Models\SeoSetting;
use App\Models\User;
use App\Services\ChatAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Customer names are not public: no page, links, names, logos or customer images on the public side. */
class CustomersHiddenQaTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['uz', 'ru', 'en'];

    private const SECRET_NAMES = ['Korzinka Secret', 'Корзинка Тайна', 'Oʻzbek Mijoz Maxfiy'];

    private Industry $retail;

    protected function setUp(): void
    {
        parent::setUp();
        SeoSetting::current()->update(['faq_enabled' => true]);

        $this->retail = Industry::create(['name' => ['uz' => 'Chakana uz', 'ru' => 'Розница ru', 'en' => 'Retail en'],
            'description' => ['uz' => 'd uz', 'ru' => 'd ru', 'en' => 'd en'], 'icon' => 'shopping-cart', 'is_active' => true, 'extra_customers' => 2]);
        foreach (self::SECRET_NAMES as $i => $name) {
            Customer::create(['name' => $name, 'logo' => "customers/secret-logo-{$i}.png", 'website' => "https://secret-client-{$i}.example",
                'description' => ['uz' => "SecretDesc{$i}", 'ru' => "SecretDesc{$i}", 'en' => "SecretDesc{$i}"],
                'industry_id' => $this->retail->id, 'is_active' => true]);
        }
        $category = ArticleCategory::create(['name' => ['uz' => 'N', 'ru' => 'N', 'en' => 'N'], 'slug' => ['uz' => 'n-uz', 'ru' => 'n-ru', 'en' => 'n-en']]);
        Article::create(['article_category_id' => $category->id, 'is_published' => true, 'published_at' => now()->subDay(),
            'title' => ['uz' => 'A', 'ru' => 'A', 'en' => 'A'], 'slug' => ['uz' => 'a-uz', 'ru' => 'a-ru', 'en' => 'a-en'],
            'content' => ['uz' => '<p>x</p>', 'ru' => '<p>x</p>', 'en' => '<p>x</p>']]);
    }

    private function assertNoCustomerData(string $html, string $where): void
    {
        foreach (self::SECRET_NAMES as $name) {
            $this->assertStringNotContainsString($name, $html, "{$where}: customer name leaked");
            $this->assertStringNotContainsString(e($name), $html, "{$where}: customer name leaked (escaped)");
        }
        $this->assertStringNotContainsString('secret-logo', $html, "{$where}: customer logo leaked");
        $this->assertStringNotContainsString('storage/customers', $html, "{$where}: customer image path");
        $this->assertStringNotContainsString('secret-client', $html, "{$where}: customer website leaked");
        $this->assertStringNotContainsString('SecretDesc', $html, "{$where}: customer description leaked");
        $this->assertDoesNotMatchRegularExpression('#/(uz|ru|en)/customers#', $html, "{$where}: /customers link");
    }

    public function test_every_public_page_hides_customers_in_all_locales(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (['', '/about', '/brands', '/industries', '/contact', '/news', '/faq', "/news/n-{$locale}", "/news/n-{$locale}/a-{$locale}"] as $page) {
                $html = $this->get("/{$locale}{$page}")->assertOk()->getContent();
                $this->assertNoCustomerData($html, "/{$locale}{$page}");
                $this->assertStringNotContainsString('site.customers', $html);
                $this->assertStringNotContainsString(e(__('site.industries.see_customers', [], $locale)), $html, "/{$locale}{$page}: See customers button");
            }
            // Nav has no Customers item (the home "clients" stat label is the same word, so match nav links only)
            $this->assertDoesNotMatchRegularExpression('#<a[^>]*>\s*'.preg_quote(__('Customers', [], $locale), '#').'\s*</a>#u', $this->get("/{$locale}")->getContent());
        }
    }

    public function test_industries_page_shows_count_only(): void
    {
        foreach (self::LOCALES as $locale) {
            $html = $this->get("/{$locale}/industries")->assertOk()->getContent();
            $this->assertStringContainsString('>5</span>', $html); // 3 listed + 2 extra
            $this->assertStringContainsString(e(trans_choice('site.industries.customers_count', 5, [], $locale)), $html);
        }
    }

    public function test_old_customers_url_redirects_301_to_industries(): void
    {
        foreach (self::LOCALES as $locale) {
            $this->get("/{$locale}/customers")->assertStatus(301)->assertRedirect("/{$locale}/industries");
        }
        $this->get('/de/customers')->assertNotFound();
    }

    public function test_seo_files_hide_customers(): void
    {
        foreach (['/sitemap.xml', '/llms.txt', '/robots.txt'] as $file) {
            $body = $this->get($file)->assertOk()->getContent();
            $this->assertNoCustomerData($body, $file);
        }
    }

    public function test_mcp_list_industries_only_counts(): void
    {
        McpSetting::current()->update(['enabled' => true, 'require_token' => false, 'tools' => null]);
        $res = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'list_industries', 'arguments' => ['locale' => 'en']]])->assertOk();
        $this->assertNoCustomerData($res->getContent(), 'mcp list_industries');
        $this->assertSame(5, $res->json('result.structuredContent.industries.0.customers'));
    }

    public function test_ai_prompt_lists_counts_and_never_names(): void
    {
        $ai = app(ChatAssistant::class);
        foreach (self::LOCALES as $locale) {
            foreach (['web', 'telegram'] as $channel) {
                $prompt = $ai->promptPreview($locale, $channel);
                $this->assertNoCustomerData($prompt, "prompt {$locale}/{$channel}");
                $this->assertStringContainsString('never name customers', $prompt);
                $this->assertStringContainsString('(5 customers)', $prompt);
            }
        }
    }

    public function test_admin_customers_crud_still_works(): void
    {
        Storage::fake('public');
        $this->get('/admin/customers')->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/admin/customers')->assertRedirect(route('admin.login'));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/customers')->assertOk()->assertSee('Korzinka Secret');
        $this->get('/admin/customers/create')->assertOk();

        $this->post('/admin/customers', ['name' => 'Новый Клиент', 'industry_id' => $this->retail->id, 'is_active' => 1,
            'logo' => UploadedFile::fake()->image('l.png')])->assertRedirect(route('admin.customers.index'));
        $c = Customer::where('name', 'Новый Клиент')->firstOrFail();
        Storage::disk('public')->assertExists($c->logo);

        $this->post('/admin/customers', ['name' => ''])->assertSessionHasErrors('name');

        $this->get("/admin/customers/{$c->id}/edit")->assertOk();
        $this->put("/admin/customers/{$c->id}", ['name' => 'Renamed', 'industry_id' => $this->retail->id])->assertRedirect();
        $this->assertSame('Renamed', $c->fresh()->name);
        $this->delete("/admin/customers/{$c->id}")->assertRedirect();
        $this->assertNull(Customer::find($c->id));
    }
}
