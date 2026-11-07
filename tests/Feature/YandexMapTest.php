<?php

namespace Tests\Feature;

use App\Models\CompanyInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The contact page map is Yandex Maps, built from a link, embed code or coordinates. */
class YandexMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_inputs(): void
    {
        $this->assertSame('ll=69.240562%2C41.311081&z=16&pt=69.240562%2C41.311081%2Cpm2rdm',
            CompanyInfo::yandexMapQuery('41.311081, 69.240562'));
        $this->assertSame('ll=69.27%2C41.31&z=17&pt=69.27%2C41.31',
            CompanyInfo::yandexMapQuery('https://yandex.uz/maps/10335/tashkent/?ll=69.27%2C41.31&z=17&pt=69.27%2C41.31&utm_source=x'));
        $this->assertSame('ll=69.2%2C41.3&z=16&oid=12345',
            CompanyInfo::yandexMapQuery('<iframe src="https://yandex.ru/map-widget/v1/?ll=69.2%2C41.3&amp;z=16&amp;oid=12345" width="560" height="400" frameborder="1"></iframe>'));
        $this->assertSame('um=constructor%3Aabc123',
            CompanyInfo::yandexMapQuery('<script type="text/javascript" charset="utf-8" async src="https://api-maps.yandex.ru/services/constructor/1.0/js/?um=constructor%3Aabc123&amp;width=500&amp;height=400&amp;lang=ru_RU"></script>'));
    }

    public function test_rejected_inputs(): void
    {
        foreach ([
            '<iframe src="https://www.google.com/maps/embed?pb=!1m18"></iframe>',
            'https://yandex.uz/maps/-/CDqXYZ',
            'https://evil.example/maps/?ll=1,2',
            'https://yandex.uz.evil.com/maps/?ll=1,2',
            'https://yandex.uz/maps/',                  // no place
            '<script>alert(1)</script>',
            '91, 200',
            '',
        ] as $input) {
            $this->assertNull(CompanyInfo::yandexMapQuery($input), $input);
        }
        $this->assertTrue(CompanyInfo::isYandexShortLink('https://yandex.uz/maps/-/CDqXYZ'));
    }

    public function test_contact_page_renders_the_widget_in_each_language(): void
    {
        CompanyInfo::instance()->update(['map_embed' => '41.311081, 69.240562']);

        foreach (['uz' => 'uz_UZ', 'ru' => 'ru_RU', 'en' => 'en_US'] as $locale => $lang) {
            $this->get(route('site.contact', $locale))
                ->assertOk()
                ->assertSee('https://yandex.uz/map-widget/v1/?ll=69.240562%2C41.311081&amp;z=16&amp;pt=69.240562%2C41.311081%2Cpm2rdm&amp;lang='.$lang, false)
                ->assertSee('https://yandex.uz/maps/?ll=69.240562', false)
                ->assertSee(__('site.contact.open_map', [], $locale));
        }
    }

    public function test_old_google_embed_is_not_rendered_and_admin_is_told(): void
    {
        CompanyInfo::instance()->update(['map_embed' => '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe><script>alert(1)</script>']);

        $this->get(route('site.contact', 'en'))
            ->assertOk()->assertDontSee('google.com/maps', false)->assertDontSee('<script>alert(1)</script>', false);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.company-info.edit'))
            ->assertOk()->assertSee('Yandex Maps (contact page)')->assertSee('not a Yandex Maps location');
    }

    public function test_admin_validation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.company-info.update'), ['map_embed' => 'https://yandex.uz/maps/-/CDqXYZ'])
            ->assertSessionHasErrors('map_embed');
        $this->actingAs($admin)->put(route('admin.company-info.update'), ['map_embed' => '<iframe src="https://maps.google.com/x"></iframe>'])
            ->assertSessionHasErrors('map_embed');

        $this->actingAs($admin)->put(route('admin.company-info.update'), ['map_embed' => 'https://yandex.uz/maps/?ll=69.27%2C41.31&z=17'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('admin.company-info.edit'))->assertOk()->assertSee('yandex.uz/map-widget/v1/?ll=69.27', false);

        $this->actingAs($admin)->put(route('admin.company-info.update'), ['map_embed' => ''])->assertSessionHasNoErrors();
        $this->get(route('site.contact', 'en'))->assertOk()->assertDontSee('map-widget', false);
    }

    public function test_company_cards_and_other_real_formats(): void
    {
        // Company card share link → that organisation.
        $this->assertSame('oid=1234567890&ol=biz&z=16', CompanyInfo::yandexMapQuery('https://yandex.uz/maps/org/bdb_commerce/1234567890/'));
        $this->assertSame('oid=1234567890&ol=biz&z=17&ll=69.2%2C41.3',
            CompanyInfo::yandexMapQuery('https://yandex.uz/maps/10335/tashkent/org/bdb_commerce/1234567890/?ll=69.2%2C41.3&z=17'));

        // Company embed code: <a> links first, then the widget iframe.
        $embed = '<div style="position:relative;overflow:hidden;"><a href="https://yandex.uz/maps/org/bdb/1234567890/?utm_medium=mapframe" style="color:#eee">BDB</a>'
            .'<a href="https://yandex.uz/maps/10335/tashkent/category/printing/184107309/">Printing</a>'
            .'<iframe src="https://yandex.uz/map-widget/v1/?ll=69.240562%2C41.311081&amp;mode=search&amp;oid=1234567890&amp;ol=biz&amp;z=16" width="560" height="400" frameborder="1" allowfullscreen="true"></iframe></div>';
        $this->assertSame('ll=69.240562%2C41.311081&mode=search&oid=1234567890&ol=biz&z=16', CompanyInfo::yandexMapQuery($embed));

        // Unencoded search text keeps its spaces; old host; upper-case path; decimal commas.
        $this->assertSame('text='.urlencode('Ташкент, Амир Темур 1'), CompanyInfo::yandexMapQuery('https://yandex.uz/maps/?text=Ташкент, Амир Темур 1'));
        $this->assertNotNull(CompanyInfo::yandexMapQuery('https://maps.yandex.ru/?ll=69.2,41.3&z=15'));
        $this->assertNotNull(CompanyInfo::yandexMapQuery('https://yandex.uz/MAPS/?ll=69.2,41.3'));
        $this->assertSame('ll=69.240562%2C41.311081&z=16&pt=69.240562%2C41.311081%2Cpm2rdm', CompanyInfo::yandexMapQuery('41,311081, 69,240562'));
        $this->assertNotNull(CompanyInfo::yandexMapQuery('41.311081,69.240562'));

        // Widget short links get the short-link hint.
        $this->assertTrue(CompanyInfo::isYandexShortLink('https://yandex.uz/map-widget/v1/-/CDqXYZ'));
        $this->assertFalse(CompanyInfo::isYandexShortLink($embed));
    }

    public function test_open_link_is_below_the_map(): void
    {
        CompanyInfo::instance()->update(['map_embed' => '41.311081, 69.240562']);

        $html = $this->get(route('site.contact', 'ru'))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, __('site.contact.open_map', [], 'ru')), strpos($html, 'map-widget/v1'));
        $this->assertStringNotContainsString('absolute bottom-3 left-3', $html);
    }
}
