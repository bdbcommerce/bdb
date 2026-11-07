<?php

namespace Tests\Feature;

use App\Models\CompanyInfo;
use App\Models\Industry;
use App\Models\McpSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Telegram bot is shown wherever contact details are shown. Plus industry extra customers. */
class TelegramBotContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_parsing(): void
    {
        foreach (['@bdb_uz_bot', 'bdb_uz_bot', 'https://t.me/bdb_uz_bot', 't.me/Bdb_Uz_Bot/', 'https://telegram.me/bdb_uz_bot?start=site'] as $input) {
            $this->assertNotNull(CompanyInfo::botUsername($input), $input);
        }
        $this->assertSame('a_bot', CompanyInfo::botUsername('a_bot')); // shortest valid (5)
        foreach (['@bdb_uz', 'https://evil.example/bdb_bot', 'abot', str_repeat('a', 30).'bot', '<script>bot', ''] as $input) {
            $this->assertNull(CompanyInfo::botUsername($input), $input);
        }
    }

    public function test_bot_contact_appears_everywhere(): void
    {
        CompanyInfo::instance()->update(['telegram_bot' => 'bdb_uz_bot', 'phone' => '+998 71 200 00 00', 'about' => ['en' => 'About']]);

        foreach (['uz', 'ru', 'en'] as $locale) {
            foreach (['site.home', 'site.contact', 'site.about'] as $route) {
                $html = $this->get(route($route, $locale))->assertOk()->getContent();
                $this->assertStringContainsString('href="https://t.me/bdb_uz_bot"', $html, "$route $locale");
                $this->assertStringContainsString('@bdb_uz_bot', $html);
                $this->assertStringNotContainsString('site.contact.telegram_bot', $html);
            }
        }
        $this->assertStringContainsString('"contactPoint":[{"@type":"ContactPoint"', $this->get('/en')->getContent());
        $this->assertStringContainsString('- Telegram bot: https://t.me/bdb_uz_bot', $this->get('/llms.txt')->getContent());

        McpSetting::current()->update(['enabled' => true, 'require_token' => false]);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_company_info']])
            ->assertJsonPath('result.structuredContent.telegram_bot', 'https://t.me/bdb_uz_bot');
    }

    public function test_env_fallback_and_admin_field(): void
    {
        config(['services.telegram.bot_username' => 'env_fallback_bot']);
        $this->get(route('site.contact', 'en'))->assertSee('https://t.me/env_fallback_bot', false);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put(route('admin.company-info.update'), ['telegram_bot' => 'https://t.me/bdb_uz_bot'])->assertSessionHasNoErrors();
        $this->assertSame('bdb_uz_bot', CompanyInfo::instance()->fresh()->telegram_bot);
        $this->actingAs($admin)->put(route('admin.company-info.update'), ['telegram_bot' => '@not_a_robot_x'])->assertSessionHasErrors('telegram_bot');
        $this->actingAs($admin)->get(route('admin.company-info.edit'))->assertOk()->assertSee('Telegram bot (contact)');
    }

    public function test_industry_extra_customers_are_counted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.industries.store'), [
            'name_uz' => 'Chakana', 'name_ru' => 'Розница', 'name_en' => 'Retail', 'icon' => 'shopping-cart', 'is_active' => '1', 'extra_customers' => 20,
        ])->assertSessionHasNoErrors();
        $industry = Industry::sole();
        $this->assertSame(20, (int) $industry->extra_customers);

        $this->actingAs($admin)->get(route('admin.industries.index'))->assertOk()->assertSee('Customers on home');
        $this->actingAs($admin)->get(route('admin.industries.edit', $industry))->assertOk()->assertSee('Extra customers (not listed)');
        $this->actingAs($admin)->put(route('admin.industries.update', $industry), ['name_uz' => 'a', 'name_ru' => 'b', 'name_en' => 'c', 'extra_customers' => -1])
            ->assertSessionHasErrors('extra_customers');

        auth()->logout();
        $this->get(route('site.home', 'ru'))->assertOk()->assertSee('20');
    }
}
