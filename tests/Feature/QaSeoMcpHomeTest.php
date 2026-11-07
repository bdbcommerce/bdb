<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use App\Models\McpSetting;
use App\Models\SeoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: SEO & AI visibility files, MCP JSON-RPC conformance, home page customer counts. */
class QaSeoMcpHomeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function article(string $suffix = ''): Article
    {
        $category = ArticleCategory::first() ?? ArticleCategory::create([
            'name' => ['en' => 'News', 'ru' => 'Новости', 'uz' => 'Yangiliklar'],
            'slug' => ['en' => 'news', 'ru' => 'novosti', 'uz' => 'yangiliklar'],
        ]);

        return Article::create([
            'article_category_id' => $category->id, 'is_published' => true, 'published_at' => now()->subMinute(),
            'title' => ['en' => 'Asl Belgi explained'.$suffix, 'ru' => 'Что такое Asl Belgi'.$suffix, 'uz' => 'Asl Belgi nima'.$suffix],
            'slug' => ['en' => 'asl-belgi-explained'.$suffix, 'ru' => 'chto-takoe-asl-belgi'.$suffix, 'uz' => 'asl-belgi-nima'.$suffix],
            'excerpt' => ['en' => 'How marking works.'], 'content' => ['en' => 'Text', 'ru' => 'Текст', 'uz' => 'Matn'],
        ]);
    }

    /** RFC 9309 groups: consecutive user-agent lines then rules until the next user-agent line. */
    private function robotsGroups(string $robots): array
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        foreach (preg_split('/\n/', $robots) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'sitemap') {
                continue;
            }
            if ($key === 'user-agent') {
                if (! $lastWasAgent) {
                    $current = count($groups);
                    $groups[$current] = ['agents' => [], 'rules' => []];
                }
                $groups[$current]['agents'][] = $value;
                $lastWasAgent = true;

                continue;
            }
            $this->assertNotNull($current, "Rule outside any group: {$line}");
            $groups[$current]['rules'][] = $key.': '.$value;
            $lastWasAgent = false;
        }

        return $groups;
    }

    public function test_robots_every_allowed_bot_group_repeats_private_disallows(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $groups = $this->robotsGroups($robots);
        $this->assertCount(count(SeoSetting::CRAWLERS) + 1, $groups);

        foreach ($groups as $group) {
            $bot = $group['agents'][0];
            if (in_array('allow: /', $group['rules'], true)) {
                foreach (SeoSetting::PRIVATE_PATHS as $path) {
                    $this->assertContains('disallow: '.$path, $group['rules'], "{$bot} group misses {$path}");
                }
            } else {
                $this->assertSame(['disallow: /'], $group['rules'], $bot);
            }
        }
        $this->assertMatchesRegularExpression('#^Sitemap: https?://\S+/sitemap\.xml$#m', $robots);
    }

    /** Extra rules without their own User-agent line should apply to all bots (the "*" group). */
    public function test_robots_extra_rules_belong_to_a_sensible_group(): void
    {
        $this->actingAs($this->admin())->put(route('admin.seo.update'), [
            'crawlers' => array_fill_keys(array_keys(SeoSetting::CRAWLERS), '1'),
            'robots_extra' => 'Disallow: /uz/drafts',
            'llms_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $groups = $this->robotsGroups($this->get('/robots.txt')->getContent());
        $star = collect($groups)->first(fn ($g) => $g['agents'] === ['*']);
        $last = end($groups);

        $this->assertContains('disallow: /uz/drafts', $star['rules'],
            'Extra rule ended up in the group of "'.$last['agents'][0].'" instead of "*"');
    }

    public function test_sitemap_is_valid_xml_and_every_url_resolves_in_all_locales(): void
    {
        $this->article();
        SeoSetting::current()->update(['faq_enabled' => true]);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xp->registerNamespace('x', 'http://www.w3.org/1999/xhtml');

        $urls = $xp->query('//s:url');
        $this->assertGreaterThan(20, $urls->length);
        $locs = [];
        foreach ($urls as $url) {
            $loc = $xp->query('s:loc', $url)->item(0)->textContent;
            $locs[] = $loc;
            $alts = [];
            foreach ($xp->query('x:link', $url) as $link) {
                $this->assertSame('alternate', $link->getAttribute('rel'));
                $alts[$link->getAttribute('hreflang')] = $link->getAttribute('href');
            }
            $this->assertSame(['uz', 'ru', 'en'], array_keys($alts), $loc);
            $this->assertContains($loc, $alts, "{$loc} is not among its own alternates");
            $lastmod = $xp->query('s:lastmod', $url)->item(0)?->textContent;
            if ($lastmod) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $lastmod);
            }
        }
        $this->assertSame(count($locs), count(array_unique($locs)), 'duplicate <loc>');

        foreach ($locs as $loc) {
            $this->get(parse_url($loc, PHP_URL_PATH))->assertOk();
        }
        $this->assertContains(route('site.news.show', ['ru', 'novosti', 'chto-takoe-asl-belgi']), $locs);
        $this->assertContains(route('site.faq', 'uz'), $locs);
    }

    public function test_sitemap_and_llms_cache_is_flushed_after_content_edits(): void
    {
        $this->article();
        $this->get('/sitemap.xml');
        $this->get('/llms.txt');

        $second = $this->article('-two');
        $this->assertStringContainsString('asl-belgi-explained-two', $this->get('/sitemap.xml')->getContent());
        $this->assertStringContainsString('Asl Belgi explained-two', $this->get('/llms.txt')->getContent());

        $second->update(['is_published' => false]);
        $this->assertStringNotContainsString('asl-belgi-explained-two', $this->get('/sitemap.xml')->getContent());

        $second->delete();
        Article::first()->delete();
        $this->assertStringNotContainsString('asl-belgi', $this->get('/sitemap.xml')->getContent());
    }

    public function test_llms_txt_format(): void
    {
        $this->article();
        $txt = $this->get('/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
        $this->assertStringStartsWith("# BDB Auto ID\n\n> ", $txt);
        $this->assertStringContainsString("\n## Main pages\n", $txt);
        $this->assertStringContainsString("\n## Articles\n", $txt);
        $this->assertStringNotContainsString('<', $txt);
        $this->assertStringNotContainsString('site.faq', $txt); // FAQ off by default
    }

    public function test_faq_page_renders_in_three_locales_without_raw_keys_and_json_ld_is_valid(): void
    {
        $this->get('/uz/faq')->assertNotFound(); // off by default
        SeoSetting::current()->update(['faq_enabled' => true]);
        KnowledgeEntry::create(['type' => 'faq', 'is_active' => true,
            'question' => ['uz' => 'Oʻzbekcha savol: yetkazib berasizmi?', 'ru' => 'Вы доставляете «по всей» стране?', 'en' => 'Do you deliver "everywhere" </script>?'],
            'answer' => ['uz' => 'Ha, butun Oʻzbekiston boʻylab.', 'ru' => 'Да, по всему Узбекистану.', 'en' => 'Yes, <b>across</b> Uzbekistan.']]);
        KnowledgeEntry::create(['type' => 'pricing', 'is_active' => true,
            'question' => ['en' => 'Secret price list?'], 'answer' => ['en' => 'Internal only']]);
        $this->article();

        foreach (['uz', 'ru', 'en'] as $l) {
            $html = $this->get("/{$l}/faq")->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/>\s*site\.[a-z_]+\.[a-z_.]+\s*</', $html, "raw key on {$l} faq");
            $this->assertStringNotContainsString('Secret price list', $html);
            $this->assertStringContainsString(route('site.faq', $l), $this->get("/{$l}")->getContent()); // footer link

            foreach (["/{$l}", "/{$l}/faq", "/{$l}/contact", "/{$l}/about", route('site.news.show', [$l, $l === 'en' ? 'news' : ($l === 'ru' ? 'novosti' : 'yangiliklar'), $l === 'en' ? 'asl-belgi-explained' : ($l === 'ru' ? 'chto-takoe-asl-belgi' : 'asl-belgi-nima')])] as $page) {
                $body = $this->get($page)->assertOk()->getContent();
                preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m);
                $this->assertNotEmpty($m[1], "no JSON-LD on {$page}");
                foreach ($m[1] as $json) {
                    $decoded = json_decode($json, true);
                    $this->assertIsArray($decoded, "invalid JSON-LD on {$page}: ".json_last_error_msg());
                }
            }
        }
        $faqHtml = $this->get('/en/faq')->getContent();
        $this->assertStringContainsString('FAQPage', $faqHtml);
    }

    public function test_admin_seo_form_validation_keeps_old_input_and_access_rules(): void
    {
        $this->get(route('admin.seo.edit'))->assertRedirect();
        $this->assertNotSame(200, $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.seo.edit'))->status());

        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.seo.edit'))->put(route('admin.seo.update'), [
            'opening_hours' => 'every day',
            'google_verification' => '"><script>x</script>',
            'llms_summary' => 'Ўзбек тилидаги қисқача маълумот',
            'robots_extra' => "Disallow: /x\nfoo bar",
        ])->assertRedirect(route('admin.seo.edit'))
            ->assertSessionHasErrors(['opening_hours', 'google_verification', 'robots_extra']);

        $html = $this->actingAs($admin)->get(route('admin.seo.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Ўзбек тилидаги қисқача маълумот', $html);
        $this->assertStringContainsString('every day', $html);
        $this->assertStringNotContainsString('"><script>x</script>', $html);

        $this->actingAs($admin)->put(route('admin.seo.update'), [
            'opening_hours' => 'Mo-Fr 09:00-18:00; Sa 10:00-14:00',
            'google_verification' => 'abc_DEF-123',
            'llms_summary' => str_repeat('a', 1501),
        ])->assertSessionHasErrors('llms_summary');

        $this->actingAs($admin)->put(route('admin.seo.update'), [
            'opening_hours' => 'Mo-Fr 09:00-18:00; Sa 10:00-14:00',
            'google_verification' => 'abc_DEF-123',
        ])->assertSessionHasNoErrors();
        $home = $this->get('/ru')->getContent();
        $this->assertStringContainsString('<meta name="google-site-verification" content="abc_DEF-123">', $home);
        $this->assertStringContainsString('"openingHours":["Mo-Fr 09:00-18:00","Sa 10:00-14:00"]', $home);
    }

    public function test_indexnow_is_skipped_on_http_app_url(): void
    {
        Http::fake();
        config(['app.url' => 'http://bdb.example']);
        SeoSetting::current()->update(['indexnow_enabled' => true]);

        $this->article(); // saved hook
        $this->actingAs($this->admin())->post(route('admin.seo.indexnow'))->assertSessionHas('success', fn ($m) => str_contains($m, 'Skipped'));
        Http::assertNothingSent();
        $this->assertStringContainsString('Skipped', SeoSetting::current()->indexnow_last_result);
    }

    // MCP -----------------------------------------------------------------

    private function mcp(mixed $payload, array $headers = [])
    {
        return $this->call('POST', '/mcp', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream']),
            is_string($payload) ? $payload : json_encode($payload));
    }

    public function test_mcp_json_rpc_conformance(): void
    {
        McpSetting::current()->update(['enabled' => true, 'require_token' => false]);

        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 'a1', 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'qa', 'version' => '1'],
        ]])->assertOk()->assertHeader('Mcp-Session-Id');
        $init->assertJsonPath('id', 'a1')->assertJsonPath('result.protocolVersion', '2025-03-26')
            ->assertJsonPath('result.serverInfo.name', 'bdb-auto-id');
        $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])
            ->assertJsonPath('result.protocolVersion', '2025-06-18');

        // Notifications: 202, empty body.
        $r = $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $r->assertStatus(202);
        $this->assertSame('', $r->getContent());

        $list = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])->assertOk()->json('result.tools');
        $names = array_column($list, 'name');
        $this->assertContains('get_company_info', $names);
        $this->assertNotContains('request_callback', $names);
        $this->assertNotContains('list_conversations', $names); // team tools never public
        foreach ($list as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertIsString($tool['description']);
        }

        $call = $this->mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'get_company_info', 'arguments' => ['locale' => 'ru']]])->assertOk();
        $call->assertJsonPath('result.isError', false)->assertJsonPath('result.content.0.type', 'text');
        $this->assertIsArray(json_decode($call->json('result.content.0.text'), true));

        $this->mcp(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'get_article', 'arguments' => ['slug' => 'nope']]])
            ->assertJsonPath('result.isError', true);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'list_conversations']])
            ->assertJsonPath('error.code', -32602);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'get_article', 'arguments' => ['slug' => ['x']]]])
            ->assertJsonPath('result.isError', true);

        // Errors.
        $this->mcp('{"jsonrpc":"2.0",')->assertStatus(400)->assertJsonPath('error.code', -32700)->assertJsonPath('id', null);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'nope'])->assertJsonPath('error.code', -32601)->assertJsonPath('id', 8);
        $this->mcp(['jsonrpc' => '1.0', 'id' => 9, 'method' => 'ping'])->assertJsonPath('error.code', -32600)->assertJsonPath('id', 9);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping'])->assertJsonPath('error.code', -32600);
        $this->mcp(['jsonrpc' => '2.0', 'id' => ['x'], 'method' => 'ping'])->assertJsonPath('error.code', -32600)->assertJsonPath('id', null);
        $this->mcp('42')->assertJsonPath('error.code', -32600);
        $this->mcp('[]')->assertJsonPath('error.code', -32600);

        // Batches.
        $batch = $this->mcp([
            ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 11, 'method' => 'nope'],
            1,
        ])->assertOk()->json();
        $this->assertTrue(array_is_list($batch));
        $this->assertCount(3, $batch);
        $this->assertSame([10, 11, null], array_column($batch, 'id'));

        $this->mcp([['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled']])
            ->assertStatus(202);
        $this->mcp(array_fill(0, 11, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']))->assertStatus(400);

        // Transport.
        $this->get('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->delete('/mcp')->assertStatus(405);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'https://evil.example'])->assertForbidden();
    }

    public function test_mcp_token_is_shown_once_and_not_stored_in_plain_text(): void
    {
        $admin = $this->admin();
        $r = $this->actingAs($admin)->post(route('admin.mcp.token'))->assertRedirect();
        $token = session('mcp_token');
        $this->assertStringStartsWith('bdb_mcp_', $token);
        $this->assertStringContainsString($token, $this->actingAs($admin)->get(route('admin.mcp.edit'))->getContent());
        $this->assertStringNotContainsString($token, $this->actingAs($admin)->get(route('admin.mcp.edit'))->getContent());
        $this->assertDatabaseMissing('mcp_settings', ['token_hash' => $token]);

        $this->actingAs($admin)->from(route('admin.mcp.edit'))->put(route('admin.mcp.update'), ['rate_limit' => 'abc'])
            ->assertSessionHasErrors('rate_limit');
        $this->actingAs($admin)->put(route('admin.mcp.update'), ['rate_limit' => 4])->assertSessionHasErrors('rate_limit');
        $this->get(route('admin.mcp.edit'))->assertOk();
        auth()->logout();
        $this->get(route('admin.mcp.edit'))->assertRedirect();
    }

    // Home page -----------------------------------------------------------

    public function test_russian_plural_forms_of_customers_count(): void
    {
        app()->setLocale('ru');
        $expect = [1 => 'клиент', 2 => 'клиента', 5 => 'клиентов', 11 => 'клиентов', 21 => 'клиент', 22 => 'клиента', 0 => 'клиентов', 111 => 'клиентов'];
        foreach ($expect as $n => $word) {
            $this->assertSame($word.' в этой отрасли', trans_choice('site.industries.customers_count', $n), "n={$n}");
        }
        app()->setLocale('en');
        $this->assertSame('customer in this industry', trans_choice('site.industries.customers_count', 1));
        $this->assertSame('customers in this industry', trans_choice('site.industries.customers_count', 2));
        app()->setLocale('uz');
        $this->assertSame('ta mijoz ushbu sohada', trans_choice('site.industries.customers_count', 5));
    }

    public function test_home_shows_only_counts_of_active_customers(): void
    {
        $industry = Industry::create(['name' => ['uz' => 'Savdo', 'ru' => 'Торговля', 'en' => 'Retail'], 'icon' => 'shopping-cart', 'is_active' => true]);
        foreach (range(1, 21) as $i) {
            Customer::create(['name' => 'Mijoz Secret '.$i, 'industry_id' => $industry->id, 'is_active' => true]);
        }
        Customer::create(['name' => 'Inactive Corp', 'industry_id' => $industry->id, 'is_active' => false]);
        Industry::create(['name' => ['uz' => 'Bo‘sh', 'ru' => 'Пусто', 'en' => 'Empty'], 'icon' => 'cube', 'is_active' => true]);

        $ru = $this->get('/ru')->assertOk();
        $this->assertMatchesRegularExpression('#>21</span>\s*<span[^>]*>клиент в этой отрасли</span>#u', $ru->getContent());
        $this->assertDoesNotMatchRegularExpression('#>22</span>#', $ru->getContent()); // inactive not counted
        $ru->assertDontSee('Mijoz Secret')->assertDontSee('Inactive Corp');
        $ru->assertSee(__('site.industries.no_customers', [], 'ru'));
        foreach (['uz', 'en'] as $l) {
            $html = $this->get("/{$l}")->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/>\s*site\.industries\.[a-z_]+\s*</', $html);
            $this->assertStringNotContainsString('Mijoz Secret', $html);
        }
    }
}
