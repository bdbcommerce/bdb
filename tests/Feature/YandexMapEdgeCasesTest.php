<?php

namespace Tests\Feature;

use App\Models\CompanyInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA edge cases for the Yandex Maps location parser and the contact page map. */
class YandexMapEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_formats(): void
    {
        $q = fn (string $in) => CompanyInfo::yandexMapQuery($in);

        $this->assertSame('whatshere%5Bpoint%5D=69.24%2C41.31&whatshere%5Bzoom%5D=17', $q('https://yandex.ru/maps/?whatshere[point]=69.24,41.31&whatshere[zoom]=17'));
        $this->assertSame('ll=69.2%2C41.3&z=16&pt=69.2%2C41.3', $q('https://yandex.uz/maps/?ll=69.2%2C41.3&amp;z=16&amp;pt=69.2%2C41.3'));
        $this->assertSame('oid=1234567890&ol=biz&z=17&ll=69.2%2C41.3', $q('https://yandex.ru/maps/10335/tashkent/org/bdb/1234567890/?ll=69.2%2C41.3&z=17')); // the company pin is kept
        foreach (['41.311081 69.240562', '41.311081;69.240562', '41.311081 ; 69.240562', '41.311081,69.240562'] as $coords) {
            $this->assertSame('ll=69.240562%2C41.311081&z=16&pt=69.240562%2C41.311081%2Cpm2rdm', $q($coords), $coords);
        }
        foreach (['90.1, 69', '41, 180.5', '-91, 0', 'https://yandex.uz/maps/?ll[a][b]=1', str_repeat('a', 4001)] as $bad) {
            $this->assertNull($q($bad), $bad);
        }
        // Injection attempts are reduced to the allowed, re-encoded parameters.
        $this->assertSame('ll=1', $q('https://yandex.uz/maps/?ll=1"><script>alert(1)</script>&z=1'));
    }

    public function test_contact_page_has_no_html_injection_in_any_locale(): void
    {
        CompanyInfo::instance()->update(['map_embed' => '<script>alert(1)</script><iframe onload="alert(2)" src="https://yandex.uz/map-widget/v1/?ll=69.2%2C41.3&amp;z=16&amp;text=%22%3E%3Cscript%3Ealert(3)%3C%2Fscript%3E"></iframe>']);

        foreach (['uz', 'ru', 'en'] as $locale) {
            $html = $this->get(route('site.contact', $locale))->assertOk()->getContent();
            $this->assertStringNotContainsString('alert(1)', $html);
            $this->assertStringNotContainsString('onload="alert(2)"', $html);
            $this->assertStringNotContainsString('<script>alert(3)', $html);
            $this->assertStringContainsString('map-widget/v1/?ll=69.2%2C41.3', $html);
            $this->assertStringContainsString(__('site.contact.open_map', [], $locale), $html);
            $this->assertStringNotContainsString('site.contact.', $html);
            $this->assertStringNotContainsString('site.shop.new_tab', $html);
        }
    }

    public function test_admin_preview_and_validation_keep_old_input(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->from(route('admin.company-info.edit'))
            ->put(route('admin.company-info.update'), ['map_embed' => 'https://yandex.uz/maps/-/CDqXYZ'])
            ->assertRedirect(route('admin.company-info.edit'));
        $this->actingAs($admin)->get(route('admin.company-info.edit'))->assertOk()
            ->assertSee('https://yandex.uz/maps/-/CDqXYZ</textarea>', false)->assertSee('Short Yandex links');

        $this->actingAs($admin)->put(route('admin.company-info.update'), ['map_embed' => '41.31, 69.24'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('admin.company-info.edit'))->assertOk()
            ->assertSee('title="Map preview"', false)->assertSee('lang=ru_RU', false)->assertSee('for="map_embed"', false);

        $this->actingAs($admin)->put(route('admin.company-info.update'), ['map_embed' => str_repeat('1', 4001)])->assertSessionHasErrors('map_embed');
    }
}
