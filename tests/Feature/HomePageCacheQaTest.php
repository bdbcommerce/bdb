<?php

namespace Tests\Feature;

use App\Http\Middleware\CacheHomePage;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\ColorTheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** QA: edge cases of the cached home page. */
class HomePageCacheQaTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $html): ?string
    {
        $i = strpos($html, 'csrf');

        return $i === false ? null : (preg_match('/[A-Za-z0-9]{40}/', str_replace('u0022', ' ', substr($html, $i, 80)), $m) ? $m[0] : null);
    }

    public function test_each_visitor_gets_their_own_csrf_token_on_a_hit(): void
    {
        $first = $this->get('/uz')->assertHeader('X-Home-Cache', 'miss');
        $t1 = $this->token($first->getContent());
        $this->assertNotNull($t1);

        $this->flushSession();
        $second = $this->get('/uz')->assertHeader('X-Home-Cache', 'hit');
        $t2 = $this->token($second->getContent());
        $this->assertNotNull($t2);
        $this->assertNotSame($t1, $t2, 'second visitor received the first visitor token');
        $this->assertSame(session()->token(), $t2);
        $this->assertStringNotContainsString($t1, Cache::get(CacheHomePage::key('uz')));
        $second->assertCookie(config('session.cookie'));
        $second->assertCookie('XSRF-TOKEN');
    }

    public function test_head_request_works(): void
    {
        $this->get('/uz');
        $this->call('HEAD', '/uz')->assertOk();
    }

    public function test_banner_and_color_theme_changes_flush(): void
    {
        $this->get('/en')->assertHeader('X-Home-Cache', 'miss');
        Banner::create(['title' => ['uz' => 'T', 'ru' => 'T', 'en' => 'QA hero slide'], 'is_active' => true, 'sort_order' => 0]);
        $this->assertStringContainsString('QA hero slide', $this->get('/en')->assertHeader('X-Home-Cache', 'miss')->getContent());

        $this->get('/en')->assertHeader('X-Home-Cache', 'hit');
        $theme = ColorTheme::create(['name' => 'QA blue', 'accent' => '#1d4ed8', 'neutral' => 'gray']);
        $this->get('/en')->assertHeader('X-Home-Cache', 'miss');
        $theme->activate();
        $this->assertFalse(Cache::has(CacheHomePage::key('en')));
        $this->assertStringContainsString('id="site-theme"', $this->get('/en')->assertHeader('X-Home-Cache', 'miss')->getContent());
    }

    public function test_brand_json_ld_is_fresh_after_a_brand_save(): void
    {
        Brand::create(['name' => ['uz' => 'OldBrandQa', 'ru' => 'OldBrandQa', 'en' => 'OldBrandQa'], 'is_active' => true]);
        $this->get('/en');
        Brand::create(['name' => ['uz' => 'NewBrandQa', 'ru' => 'NewBrandQa', 'en' => 'NewBrandQa'], 'is_active' => true]);
        $html = $this->get('/en')->assertHeader('X-Home-Cache', 'miss')->getContent();
        $this->assertMatchesRegularExpression('/ld\+json">[^<]*NewBrandQa/', $html);
    }

    public function test_each_scheme_and_host_has_its_own_copy(): void
    {
        $this->get('http://first-host.test/uz')->assertHeader('X-Home-Cache', 'miss');
        $html = $this->get('https://second-host.test/uz')->assertHeader('X-Home-Cache', 'miss')->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://second-host.test/uz">', $html);
        $this->get('https://second-host.test/uz')->assertHeader('X-Home-Cache', 'hit');
        $this->assertStringContainsString('http://first-host.test/uz', $this->get('http://first-host.test/uz')->assertHeader('X-Home-Cache', 'hit')->getContent());
    }

    public function test_non_admin_signed_in_user_bypasses_and_logged_in_miss_is_not_stored(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/uz')->assertOk()->assertHeaderMissing('X-Home-Cache');
        $this->assertFalse(Cache::has(CacheHomePage::key('uz')));
    }

    public function test_utm_query_does_not_leak_into_cached_copy(): void
    {
        $this->get('/uz?utm_source=leak_marker_qa&utm_campaign=x')->assertHeader('X-Home-Cache', 'miss');
        $this->assertStringNotContainsString('leak_marker_qa', Cache::get(CacheHomePage::key('uz')));
    }

    public function test_array_query_param_does_not_crash(): void
    {
        $this->get('/uz?utm_source[]=a')->assertOk();
        $this->get('/uz?0=a')->assertOk()->assertHeaderMissing('X-Home-Cache');
    }
}
