<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\LinkClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Security / QA probes for /go/brand/{brand}/{target} and the "Brand link clicks" analytics table. */
class SecurityBrandLinkTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    private function brand(array $attrs = []): Brand
    {
        return Brand::create($attrs + ['name' => ['en' => 'Zebra', 'ru' => 'Zebra', 'uz' => 'Zebra'], 'website' => 'https://www.zebra.com', 'shop_url' => 'https://mylabel.uz/catalog?brand=zebra', 'is_active' => true]);
    }

    /** Values written straight to the DB (bypassing admin validation) never become a redirect. */
    public function test_unsafe_stored_values_are_never_redirected_to(): void
    {
        $this->withoutDefer();
        $bad = [
            'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', "https://evil.test/\r\nSet-Cookie: x=1",
            "https://evil.test/%0d%0aX: y\nZ", '//evil.test', 'ftp://evil.test', ' https://a.test/ b',
        ];
        foreach ($bad as $value) {
            $brand = $this->brand();
            DB::table('brands')->where('id', $brand->id)->update(['website' => $value, 'shop_url' => $value]);
            foreach (['website', 'shop'] as $target) {
                $res = $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$brand, $target]));
                if ($res->isRedirect()) {
                    $loc = $res->headers->get('Location');
                    $this->assertMatchesRegularExpression('#^https?://#i', $loc, json_encode($value));
                    $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $loc);
                } else {
                    $res->assertNotFound();
                }
            }
        }
        $this->assertSame(0, LinkClick::count());
    }

    public function test_inactive_brand_unknown_target_and_query_cannot_redirect(): void
    {
        $brand = $this->brand(['is_active' => false]);
        $this->get(route('go.brand', [$brand, 'website']))->assertNotFound();

        $active = $this->brand();
        $this->get('/go/brand/'.$active->id.'/logo')->assertNotFound();
        $this->get('/go/brand/999/website')->assertNotFound();
        $this->get(route('go.brand', [$active, 'website', 'url' => 'https://evil.test', 'to' => 'https://evil.test']))
            ->assertRedirect('https://www.zebra.com');
    }

    public function test_throttle_caps_requests_per_ip(): void
    {
        $brand = $this->brand();
        for ($i = 0; $i < 20; $i++) {
            $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$brand, 'shop']))->assertRedirect();
        }
        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$brand, 'shop']))->assertStatus(429);
    }

    /** Admin table output is escaped. */
    public function test_brand_name_is_escaped_in_the_admin_table(): void
    {
        $this->withoutDefer();
        $brand = $this->brand(['name' => ['en' => '<img src=x onerror=alert(1)>', 'ru' => 'x', 'uz' => 'x']]);
        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$brand, 'website']));
        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.analytics'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    /** FINDING (QA): two deleted brands are merged into one row under one name (grouped by brand_id = NULL). */
    public function test_deleted_brands_keep_separate_rows(): void
    {
        $this->withoutDefer();
        $a = $this->brand(['name' => ['en' => 'Alpha', 'ru' => 'Alpha', 'uz' => 'Alpha']]);
        $b = $this->brand(['name' => ['en' => 'Beta', 'ru' => 'Beta', 'uz' => 'Beta']]);
        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$a, 'website']));
        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$b, 'website']));
        $a->delete();
        $b->delete();

        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.analytics'))->assertOk()->getContent();
        $this->assertStringContainsString('Alpha (deleted)', $html);
        $this->assertStringContainsString('Beta (deleted)', $html);
    }

    /** FINDING (QA): a switched-off brand that still has clicks in the period is labelled "(deleted)". */
    public function test_inactive_brand_is_not_labelled_deleted(): void
    {
        $this->withoutDefer();
        $brand = $this->brand();
        $this->withHeader('User-Agent', self::UA)->get(route('go.brand', [$brand, 'website']));
        $brand->update(['is_active' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.analytics'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Zebra (deleted)', $html);
    }

    /** FINDING (low): common link-preview fetchers and prerender requests are counted as people. */
    public function test_link_previews_and_prerender_are_not_counted(): void
    {
        $this->withoutDefer();
        $brand = $this->brand();
        $this->withHeader('User-Agent', 'WhatsApp/2.23.20.0 A')->get(route('go.brand', [$brand, 'shop']));
        $this->withHeader('User-Agent', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)')->get(route('go.brand', [$brand, 'shop']));
        $this->withHeaders(['User-Agent' => self::UA, 'Sec-Purpose' => 'prefetch;prerender'])->get(route('go.brand', [$brand, 'shop']));

        $this->assertSame(0, LinkClick::count());
    }

    /** Evidence (passes): one IP inflates "visitors" just by changing the User-Agent. */
    public function test_one_ip_can_inflate_visitors_by_rotating_user_agent(): void
    {
        $this->withoutDefer();
        $brand = $this->brand();
        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('User-Agent', self::UA.' r'.$i)->get(route('go.brand', [$brand, 'shop']));
        }
        $this->assertSame(5, LinkClick::distinct()->count('visitor_hash'));
    }

    /** FINDING (low/QA): the brands page shows a "Website" link even when the stored value cannot be redirected to (404). */
    public function test_brands_page_hides_website_link_that_would_404(): void
    {
        $brand = $this->brand(['shop_url' => null]);
        DB::table('brands')->where('id', $brand->id)->update(['website' => 'javascript:alert(1)']);
        $this->get('/en/brands')->assertOk()->assertDontSee(route('go.brand', [$brand, 'website', 'l' => 'en']), false);
    }
}
