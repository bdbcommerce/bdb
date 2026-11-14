<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\LinkClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Clicks on a brand's website / "Buy online" link are counted, then the visitor is redirected. */
class BrandLinkClicksTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    private function brand(array $attrs = []): Brand
    {
        return Brand::create($attrs + ['name' => ['en' => 'Zebra', 'ru' => 'Zebra', 'uz' => 'Zebra'], 'website' => 'https://www.zebra.com', 'shop_url' => 'https://mylabel.uz/catalog?brand=zebra', 'is_active' => true]);
    }

    public function test_brands_page_links_go_through_the_counter(): void
    {
        $brand = $this->brand();

        $html = $this->get('/ru/brands')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('go.brand', [$brand, 'website', 'l' => 'ru']).'"', $html);
        $this->assertStringContainsString('href="'.route('go.brand', [$brand, 'shop', 'l' => 'ru']).'"', $html);
        $this->assertStringNotContainsString('href="https://www.zebra.com"', $html);
    }

    public function test_a_click_is_counted_and_redirected(): void
    {
        $brand = $this->brand();

        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$brand, 'website', 'l' => 'uz']))
            ->assertRedirect('https://www.zebra.com')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$brand, 'shop']))
            ->assertRedirect('https://mylabel.uz/catalog?brand=zebra&utm_source=bdb_site&utm_medium=referral&utm_campaign=brand_card');

        $this->assertSame(['website' => 1, 'shop' => 1], LinkClick::pluck('target')->countBy()->all());
        $click = LinkClick::where('target', 'website')->sole();
        $this->assertSame([$brand->id, 'Zebra', 'uz', 'desktop'], [$click->brand_id, $click->brand_name, $click->locale, $click->device]);
    }

    public function test_bots_previews_and_admins_are_not_counted(): void
    {
        $brand = $this->brand();
        $url = route('go.brand', [$brand, 'website']);

        $this->withHeader('User-Agent', 'Googlebot/2.1')->get($url)->assertRedirect('https://www.zebra.com');
        $this->withHeaders(['User-Agent' => self::UA, 'Sec-Purpose' => 'prefetch'])->get($url)->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->withHeader('User-Agent', self::UA)->get($url)->assertRedirect();

        $this->assertSame(0, LinkClick::count());
    }

    public function test_only_the_saved_link_of_an_active_brand_no_open_redirect(): void
    {
        $noLinks = $this->brand(['website' => null, 'shop_url' => null]);
        $hidden = $this->brand(['is_active' => false]);
        $bad = $this->brand(['website' => 'javascript:alert(1)']);

        $this->get(route('go.brand', [$noLinks, 'website']))->assertNotFound();
        $this->get(route('go.brand', [$noLinks, 'shop']))->assertNotFound();
        $this->get(route('go.brand', [$hidden, 'website']))->assertNotFound();
        $this->get(route('go.brand', [$bad, 'website']))->assertNotFound();
        $this->get('/go/brand/'.$noLinks->id.'/other?to=https://evil.example')->assertNotFound();
        $this->get('/go/brand/999/website')->assertNotFound();
        $this->assertSame(0, LinkClick::count());

        $this->get('/robots.txt')->assertSee('Disallow: /go/');
    }

    public function test_analytics_shows_clicks_per_brand_including_unclicked_and_deleted_brands(): void
    {
        $zebra = $this->brand();
        $godex = $this->brand(['name' => ['en' => 'Godex'], 'shop_url' => null]);
        $gone = $this->brand(['name' => ['en' => 'Oldbrand']]);
        foreach (['10.0.0.1', '10.0.0.1', '10.0.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', self::UA)->get(route('go.brand', [$zebra, 'website']));
        }
        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$gone, 'website']));
        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$gone, 'shop']));
        $gone->delete();

        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.analytics'))->assertOk()->getContent();
        preg_match('#id="brand-clicks".*?</table>#s', $html, $m);
        $table = preg_replace('/\s+/', ' ', strip_tags($m[0]));

        $this->assertStringContainsString('Zebra 2 2 0 0', $table, 'the repeat click by the same visitor counts once');
        $this->assertStringContainsString('Godex 0 0 —', $table, 'a link nobody clicked; no shop link');
        $this->assertStringContainsString('Oldbrand (deleted) 1 1 1 1', $table);
        $this->assertStringContainsString('Total 3 1', $table);
    }
}
