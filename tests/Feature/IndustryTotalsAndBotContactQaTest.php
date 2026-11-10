<?php

namespace Tests\Feature;

use App\Models\CompanyInfo;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA: industry customer totals on the home page and the Telegram bot contact, edge cases. */
class IndustryTotalsAndBotContactQaTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['uz', 'ru', 'en'];

    private const PAGES = ['site.home', 'site.about', 'site.brands', 'site.industries', 'site.contact', 'site.news'];

    private function industry(string $name, int $extra = 0, bool $active = true): Industry
    {
        return Industry::create([
            'name' => ['uz' => "$name uz", 'ru' => "$name ru", 'en' => "$name en"],
            'icon' => 'truck',
            'extra_customers' => $extra,
            'is_active' => $active,
        ]);
    }

    private function section(string $html): string
    {
        $start = strpos($html, '<section id="industries"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    private function jsonLd(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));
        $data = json_decode($m[1], true);
        $this->assertIsArray($data, 'JSON-LD does not parse: '.json_last_error_msg());

        return $data;
    }

    public function test_all_pages_render_with_and_without_bot_and_phone(): void
    {
        $this->industry('Retail', 3);
        $cases = [
            'bot+phone' => ['telegram_bot' => 'bdb_uz_bot', 'phone' => '+998 71 200 00 00'],
            'bot only' => ['telegram_bot' => 'bdb_uz_bot', 'phone' => null, 'email' => null],
            'phone only' => ['telegram_bot' => null, 'phone' => '+998 71 200 00 00'],
            'none' => ['telegram_bot' => null, 'phone' => null, 'email' => null],
        ];
        foreach ($cases as $case => $attrs) {
            CompanyInfo::instance()->update($attrs);
            foreach (self::LOCALES as $locale) {
                foreach (self::PAGES as $route) {
                    $html = $this->get(route($route, $locale))->assertOk()->getContent();
                    $this->assertDoesNotMatchRegularExpression('/>\s*site\.[a-z_]+\.[a-z_.]+\s*</', $html, "raw key on $route $locale ($case)");
                    $this->assertStringNotContainsString('site.contact.telegram_bot', $html);
                    $this->assertStringNotContainsString('site.industries.customers_', $html);
                    $ld = $this->jsonLd($html);
                    if ($attrs['telegram_bot']) {
                        $this->assertSame('https://t.me/bdb_uz_bot', $ld['contactPoint'][0]['url'], "$route $locale ($case)");
                        // footer + mobile menu at least
                        $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="https://t.me/bdb_uz_bot"'), "$route $locale ($case)");
                    } else {
                        $this->assertArrayNotHasKey('contactPoint', $ld);
                        $this->assertStringNotContainsString('https://t.me/', $html, "$route $locale ($case)");
                    }
                }
            }
        }
    }

    public function test_bot_handle_cannot_inject_markup(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        foreach (['javascript:alert(1)//bot', 'https://t.me/x"onmouseover=alert(1)//bot', '<img src=x onerror=alert(1)>bot', 'bdb_uz_bot"><script>', 'https://evil.example/t.me/bdb_uz_bot'] as $bad) {
            $this->actingAs($admin)->from(route('admin.company-info.edit'))
                ->put(route('admin.company-info.update'), ['telegram_bot' => $bad])
                ->assertSessionHasErrors('telegram_bot');
        }

        // A bad value written straight into the DB is never rendered.
        CompanyInfo::instance()->forceFill(['telegram_bot' => '"><script>alert(1)</script>bot'])->save();
        auth()->logout();
        $html = $this->get('/en')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('https://t.me/', $html);
    }

    public function test_admin_forms_keep_old_input_on_error(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->from(route('admin.company-info.edit'))
            ->put(route('admin.company-info.update'), ['telegram_bot' => 'not_a_robot_x'])
            ->assertRedirect(route('admin.company-info.edit'));
        $this->actingAs($admin)->get(route('admin.company-info.edit'))
            ->assertOk()->assertSee('value="not_a_robot_x"', false)->assertSee('must end with');

        $this->actingAs($admin)->from(route('admin.industries.create'))
            ->post(route('admin.industries.store'), ['name_uz' => 'Soha', 'name_ru' => '', 'name_en' => 'X', 'extra_customers' => 'abc'])
            ->assertSessionHasErrors(['name_ru', 'extra_customers']);
        $this->actingAs($admin)->get(route('admin.industries.create'))
            ->assertOk()->assertSee('value="abc"', false)->assertSee('value="Soha"', false);

        // Empty extra_customers saves as 0.
        $this->actingAs($admin)->post(route('admin.industries.store'), ['name_uz' => 'a', 'name_ru' => 'b', 'name_en' => 'c', 'extra_customers' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, (int) Industry::sole()->extra_customers);
        $this->actingAs($admin)->post(route('admin.industries.store'), ['name_uz' => 'a', 'name_ru' => 'b', 'name_en' => 'c', 'extra_customers' => 1000001])
            ->assertSessionHasErrors('extra_customers');
    }

    public function test_admin_pages_require_admin(): void
    {
        $industry = $this->industry('Retail');
        foreach ([route('admin.industries.index'), route('admin.industries.edit', $industry), route('admin.company-info.edit')] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('admin.industries.index'))->assertRedirect(route('admin.login'));
    }

    public function test_russian_plurals_and_stats_include_extras(): void
    {
        $expected = [1 => 'клиент в этой отрасли', 2 => 'клиента в этой отрасли', 5 => 'клиентов в этой отрасли', 21 => 'клиент в этой отрасли'];
        foreach ($expected as $total => $text) {
            Industry::query()->delete();
            Customer::query()->delete();
            $ind = $this->industry('Retail', $total - 1);
            Customer::create(['name' => 'Listed', 'industry_id' => $ind->id, 'is_active' => true]);
            Customer::create(['name' => 'Inactive', 'industry_id' => $ind->id, 'is_active' => false]);
            $this->industry('Hidden', 100, false); // inactive industry: not shown, extras not counted

            $html = $this->get('/ru')->assertOk()->getContent();
            $section = $this->section($html);
            $this->assertMatchesRegularExpression('/>'.$total.'<\/span>\s*<span class="text-sm font-medium text-gray-700">'.$text.'</u', $section, "total $total");
            $this->assertStringNotContainsString('Hidden', $section);
            $this->assertStringNotContainsString('Listed', $section);
            $this->assertStringNotContainsString('101', $html);
        }
    }

    public function test_mobile_tablist_scrolls_and_panel_has_no_fixed_width(): void
    {
        $this->industry(str_repeat('Очень длинное название отрасли ', 4), 2);
        $section = $this->section($this->get('/ru')->assertOk()->getContent());
        $this->assertStringContainsString('overflow-x-auto', $section);
        $this->assertDoesNotMatchRegularExpression('/\bw-\[\d+px\]|\bmin-w-\[\d+px\]/', $section);
    }
}
