<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Brand;
use App\Models\CompanyInfo;
use App\Models\KnowledgeEntry;
use App\Models\SeoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Admin → SEO & AI visibility: robots.txt, sitemap.xml, llms.txt, FAQ, structured data, IndexNow. */
class SeoVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function article(): Article
    {
        $category = ArticleCategory::create(['name' => ['en' => 'News', 'ru' => 'Новости', 'uz' => 'Yangiliklar'], 'slug' => ['en' => 'news', 'ru' => 'novosti', 'uz' => 'yangiliklar']]);

        return Article::create([
            'article_category_id' => $category->id, 'is_published' => true, 'published_at' => now(),
            'title' => ['en' => 'Asl Belgi explained', 'ru' => 'Что такое Asl Belgi', 'uz' => 'Asl Belgi nima'],
            'slug' => ['en' => 'asl-belgi-explained', 'ru' => 'chto-takoe-asl-belgi', 'uz' => 'asl-belgi-nima'],
            'excerpt' => ['en' => 'How product marking works.'], 'content' => ['en' => 'Text'],
        ]);
    }

    public function test_robots_txt_is_generated_with_crawler_rules_and_full_sitemap_url(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'));

        $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
        $this->assertStringContainsString("User-agent: *\nAllow: /\nDisallow: /admin", $robots);
        $this->assertStringContainsString("User-agent: OAI-SearchBot\nAllow: /\nDisallow: /admin", $robots);
        $this->assertStringContainsString("User-agent: Bytespider\nDisallow: /\n", $robots); // blocked by default
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $robots);

        $this->actingAs($this->admin())->put(route('admin.seo.update'), [
            'crawlers' => ['GPTBot' => '0', 'Bytespider' => '1'] + array_fill_keys(array_diff(array_keys(SeoSetting::CRAWLERS), ['GPTBot', 'Bytespider']), '1'),
            'robots_extra' => "# drafts\nDisallow: /uz/drafts",
            'llms_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $robots = $this->get('/robots.txt')->getContent();
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /\n", $robots);
        $this->assertStringContainsString("User-agent: Bytespider\nAllow: /", $robots);
        $this->assertStringContainsString('Disallow: /uz/drafts', $robots);

        $this->actingAs($this->admin())->put(route('admin.seo.update'), ['robots_extra' => '<script>bad</script>'])
            ->assertSessionHasErrors('robots_extra');
    }

    public function test_sitemap_lists_every_language_with_alternates_and_correct_article_urls(): void
    {
        $this->assertFileDoesNotExist(public_path('sitemap.xml'));
        $this->article();

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>'.route('site.about', 'ru').'</loc>', $xml);
        $this->assertStringContainsString('hreflang="uz" href="'.route('site.about', 'uz').'"', $xml);
        $this->assertStringContainsString('<loc>'.route('site.news.show', ['en', 'news', 'asl-belgi-explained']).'</loc>', $xml);
        $this->assertStringNotContainsString('/news/article/', $xml); // the old, broken article path
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->get(route('site.news.show', ['en', 'news', 'asl-belgi-explained']))->assertOk();
    }

    public function test_llms_txt_summarises_the_company(): void
    {
        CompanyInfo::instance()->update(['phone' => '+998 71 200 00 00', 'about' => ['en' => 'BDB supplies barcode and RFID equipment in Uzbekistan.']]);
        Brand::create(['name' => ['en' => 'Zebra'], 'description' => ['en' => 'Label printers'], 'is_active' => true]);
        $this->article();

        $txt = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringStartsWith("# BDB Auto ID\n\n> BDB supplies barcode and RFID equipment in Uzbekistan.", $txt);
        $this->assertStringContainsString('- Phone: +998 71 200 00 00', $txt);
        $this->assertStringContainsString('- Zebra: Label printers', $txt);
        $this->assertStringContainsString('[Asl Belgi explained]('.route('site.news.show', ['en', 'news', 'asl-belgi-explained']).')', $txt);

        $this->actingAs($this->admin())->put(route('admin.seo.update'), ['llms_enabled' => '0'])->assertSessionHasNoErrors();
        $this->get('/llms.txt')->assertNotFound();
    }

    public function test_faq_page_and_structured_data(): void
    {
        KnowledgeEntry::create(['type' => 'faq', 'question' => ['ru' => 'Вы доставляете по Узбекистану?'], 'answer' => ['ru' => 'Да, по всей стране.'], 'is_active' => true]);
        KnowledgeEntry::create(['type' => 'pricing', 'question' => ['ru' => 'Внутренняя наценка?'], 'answer' => ['ru' => '30%'], 'is_active' => true]);

        $this->get(route('site.faq', 'ru'))->assertNotFound(); // off by default

        $this->actingAs($this->admin())->put(route('admin.seo.update'), [
            'faq_enabled' => '1', 'faq_types' => ['faq'], 'opening_hours' => 'Mo-Fr 09:00-18:00; Sa 10:00-14:00',
            'google_verification' => 'abc_123', 'yandex_verification' => 'f00d',
        ])->assertSessionHasNoErrors();
        CompanyInfo::instance()->update(['map_embed' => '41.311081, 69.240562']);

        $html = $this->get(route('site.faq', 'ru'))->assertOk()
            ->assertSee('Вы доставляете по Узбекистану?')->assertDontSee('Внутренняя наценка')
            ->assertSee('"@type":"FAQPage"', false)
            ->getContent();
        $this->assertStringContainsString('"openingHours":["Mo-Fr 09:00-18:00","Sa 10:00-14:00"]', $html);
        $this->assertStringContainsString('"geo":{"@type":"GeoCoordinates","latitude":41.311081,"longitude":69.240562}', $html);
        $this->assertStringContainsString('<meta name="google-site-verification" content="abc_123">', $html);
        $this->assertStringContainsString('<meta name="yandex-verification" content="f00d">', $html);
        $this->assertStringContainsString(route('site.faq', 'ru'), $this->get('/sitemap.xml')->getContent());
        $this->get(route('site.home', 'en'))->assertSee(route('site.faq', 'en'), false); // footer link

        $this->actingAs($this->admin())->put(route('admin.seo.update'), ['opening_hours' => 'weekdays 9-6'])->assertSessionHasErrors('opening_hours');
        $this->actingAs($this->admin())->put(route('admin.seo.update'), ['google_verification' => '"><script>'])->assertSessionHasErrors('google_verification');
    }

    public function test_indexnow_key_file_and_submission(): void
    {
        config(['app.url' => 'https://bdb.uz']);
        url()->forceRootUrl('https://bdb.uz');
        $this->withoutDefer(); // run the after-response IndexNow call right away
        Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
        $key = SeoSetting::current()->indexNowKey();

        $this->get('/'.$key.'.txt')->assertNotFound(); // off by default

        $this->actingAs($this->admin())->put(route('admin.seo.update'), ['indexnow_enabled' => '1'])->assertSessionHasNoErrors();
        $this->get('/'.$key.'.txt')->assertOk()->assertSeeText($key);
        $this->get('/wrongkey12345678.txt')->assertNotFound();

        $this->actingAs($this->admin())->post(route('admin.seo.indexnow'))->assertSessionHas('success', fn ($m) => str_contains($m, 'Sent'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.indexnow.org/indexnow'
            && $r['host'] === 'bdb.uz' && $r['key'] === $key && $r['keyLocation'] === 'https://bdb.uz/'.$key.'.txt'
            && in_array('https://bdb.uz/en/about', $r['urlList'], true));

        // A published article is sent when saved.
        $this->article();
        Http::assertSent(fn (Request $r) => in_array('https://bdb.uz/en/news/news/asl-belgi-explained', $r['urlList'] ?? [], true));
        $this->assertNotNull(SeoSetting::current()->indexnow_last_at);
    }

    public function test_admin_page_menu_and_access(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.seo.edit'))
            ->assertOk()
            ->assertSee('SEO &amp; AI visibility', false)
            ->assertSee('OAI-SearchBot')->assertSee('YandexBot')->assertSee('Claude-SearchBot')
            ->assertSee('robots.txt')->assertSee('llms.txt')->assertSee('Webmaster accounts');
        $this->actingAs($admin)->post(route('admin.seo.refresh'))->assertSessionHas('success');

        auth()->logout();
        $this->get(route('admin.seo.edit'))->assertRedirect(route('admin.login'));
        $this->put(route('admin.seo.update'), [])->assertRedirect(route('admin.login'));
    }
}
