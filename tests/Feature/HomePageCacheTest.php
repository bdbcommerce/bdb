<?php

namespace Tests\Feature;

use App\Http\Middleware\CacheHomePage;
use App\Models\Brand;
use App\Models\PageView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The public home page is served from a cached copy per language for 6 hours. */
class HomePageCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_visit_is_served_from_the_cache_without_database_queries(): void
    {
        $this->get('/uz')->assertOk()->assertHeader('X-Home-Cache', 'miss');

        DB::enableQueryLog();
        $html = $this->get('/uz')->assertOk()->assertHeader('X-Home-Cache', 'hit')->getContent();
        $queries = collect(DB::getQueryLog())->pluck('query')->reject(fn ($q) => str_contains($q, 'sessions') || str_contains($q, 'cache') || str_contains($q, 'page_views'));
        $this->assertCount(0, $queries, 'home content queries on a cache hit: '.$queries->implode(' | '));
        $this->assertStringContainsString('<html', $html);
        $this->assertStringNotContainsString('__HOME_CSRF_TOKEN__', $html);
        $this->assertStringContainsString('__HOME_CSRF_TOKEN__', Cache::get(CacheHomePage::key('uz')), 'no real session token is stored in the shared copy');
    }

    public function test_each_language_has_its_own_copy(): void
    {
        $this->get('/uz')->assertHeader('X-Home-Cache', 'miss');
        $this->get('/ru')->assertHeader('X-Home-Cache', 'miss');
        $ru = $this->get('/ru')->assertHeader('X-Home-Cache', 'hit')->getContent();
        $this->assertStringContainsString('lang="ru"', $ru);
        $this->assertTrue(Cache::has(CacheHomePage::key('uz')) && Cache::has(CacheHomePage::key('ru')));
    }

    public function test_expires_after_six_hours(): void
    {
        $this->get('/en')->assertHeader('X-Home-Cache', 'miss');
        $this->travel(6 * 3600 - 60)->seconds();
        $this->get('/en')->assertHeader('X-Home-Cache', 'hit');
        $this->travel(120)->seconds();
        $this->get('/en')->assertHeader('X-Home-Cache', 'miss');
    }

    public function test_content_saves_rebuild_it(): void
    {
        $this->get('/uz')->assertHeader('X-Home-Cache', 'miss');
        $this->get('/uz')->assertHeader('X-Home-Cache', 'hit');

        Brand::create(['name' => ['uz' => 'Yangi brend', 'ru' => 'Yangi brend', 'en' => 'Yangi brend'], 'is_active' => true]);
        $this->assertFalse(Cache::has(CacheHomePage::key('uz')));
        $this->get('/uz')->assertHeader('X-Home-Cache', 'miss');
    }

    public function test_admins_and_unknown_query_strings_bypass_it_and_tracking_query_strings_do_not(): void
    {
        $this->get('/uz')->assertHeader('X-Home-Cache', 'miss');

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/uz')->assertOk()->assertHeaderMissing('X-Home-Cache');
        auth()->logout();

        $this->get('/uz?theme_preview=1')->assertOk()->assertHeaderMissing('X-Home-Cache');
        $this->get('/uz?utm_source=telegram&gclid=x')->assertOk()->assertHeader('X-Home-Cache', 'hit');
    }

    public function test_cached_visits_are_still_counted_in_site_analytics(): void
    {
        $this->get('/uz');
        $this->get('/uz')->assertHeader('X-Home-Cache', 'hit');
        $this->assertSame(2, PageView::count());
    }

    public function test_the_deploy_command_clears_it(): void
    {
        $this->get('/uz')->assertHeader('X-Home-Cache', 'miss');
        $this->artisan('site:clear-home-cache')->assertSuccessful();
        $this->get('/uz')->assertHeader('X-Home-Cache', 'miss');
    }

    public function test_other_pages_are_not_cached(): void
    {
        $this->get('/uz/about')->assertOk()->assertHeaderMissing('X-Home-Cache');
    }
}
