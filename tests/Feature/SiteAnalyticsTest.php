<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → Site analytics: page views, sources (AI assistants), crawlers. No IP stored. */
class SiteAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
    }

    public function test_views_are_counted_with_source_device_and_no_ip(): void
    {
        $this->get('/ru/about', ['User-Agent' => self::CHROME, 'Referer' => 'https://chatgpt.com/'])->assertOk();
        $this->get('/en/brands?utm_source=Newsletter', ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile/15E148', 'Referer' => 'https://www.google.com/'])->assertOk();
        $this->get('/uz/contact', ['User-Agent' => self::CHROME, 'Referer' => url('/uz')])->assertOk();

        $views = PageView::orderBy('id')->get();
        $this->assertCount(3, $views);
        $this->assertSame(['/ru/about', 'site.about', 'ru', 'chatgpt.com', 'ai', 'desktop'], [$views[0]->path, $views[0]->route, $views[0]->locale, $views[0]->referrer_host, $views[0]->source, $views[0]->device]);
        $this->assertSame(['search', 'google.com', 'newsletter', 'mobile'], [$views[1]->source, $views[1]->referrer_host, $views[1]->utm_source, $views[1]->device]);
        $this->assertSame(['internal', null], [$views[2]->source, $views[2]->referrer_host]);
        $this->assertSame(16, strlen($views[0]->visitor_hash));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($views->toArray()));
    }

    public function test_crawlers_admins_errors_and_prefetch_are_handled(): void
    {
        $this->get('/robots.txt', ['User-Agent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)'])->assertOk();
        $this->get('/en', ['User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0)'])->assertOk();
        $this->get('/en', ['User-Agent' => 'python-requests/2.31'])->assertOk();
        $this->get('/en/news/nope/nope', ['User-Agent' => self::CHROME])->assertNotFound();
        $this->get('/en', ['User-Agent' => self::CHROME, 'Sec-Purpose' => 'prefetch'])->assertOk();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/en', ['User-Agent' => self::CHROME])->assertOk();

        $this->assertSame(['OAI-SearchBot', 'YandexBot', 'Other bot'], PageView::orderBy('id')->pluck('bot')->all());
        $this->assertSame(3, PageView::where('is_bot', true)->count());
    }

    public function test_admin_page_shows_the_numbers(): void
    {
        $this->get('/ru/about', ['User-Agent' => self::CHROME, 'Referer' => 'https://www.perplexity.ai/search?q=bdb'])->assertOk();
        $this->get('/ru/about', ['User-Agent' => self::CHROME])->assertOk();
        $this->get('/llms.txt', ['User-Agent' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0)'])->assertOk();
        PageView::create(['visited_at' => now()->subDays(200), 'path' => '/old', 'source' => 'direct']);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Site analytics')
            ->assertSee('/ru/about')
            ->assertSee('Perplexity · perplexity.ai')
            ->assertSee('ClaudeBot')
            ->assertSee(PageView::SOURCES['ai'])
            ->assertDontSee('/old'); // outside the 30-day period
        $this->actingAs($admin)->get(route('admin.analytics', ['days' => 365]))->assertOk()->assertSee('/old');
        $this->actingAs($admin)->get(route('admin.analytics', ['days' => 'x']))->assertOk();

        auth()->logout();
        $this->get(route('admin.analytics'))->assertRedirect(route('admin.login'));
    }
}
