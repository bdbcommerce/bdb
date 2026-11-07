<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\KnowledgeEntry;
use App\Models\McpSetting;
use App\Models\SeoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Security checks for the internet-facing MCP server and SEO files.
 * Tests named test_finding_* prove open findings and are expected to FAIL until fixed.
 */
class SecurityMcpSeoTest extends TestCase
{
    use RefreshDatabase;

    private function enable(array $tools = [], int $rateLimit = 60): void
    {
        McpSetting::current()->update(['enabled' => true, 'require_token' => false, 'tools' => $tools ?: null, 'rate_limit' => $rateLimit]);
    }

    private function msg(string $tool, array $args = [], int $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]];
    }

    // --- Open findings (expected to fail) ----------------------------------------------------

    /** F1: a batch of 20 messages costs one rate-limit hit, so the real limit is 20x the setting. */
    public function test_finding_batch_messages_count_against_rate_limit(): void
    {
        $this->enable(rateLimit: 5);
        $batch = array_map(fn ($i) => $this->msg('list_articles', [], $i), range(1, 20));

        $served = 0;
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/mcp', $batch);
            if ($response->status() === 200) {
                $served += count($response->json());
            }
        }

        $this->assertLessThanOrEqual(5, $served, "rate_limit=5/min but {$served} tool calls were served in one minute");
    }

    /** F2: the "Open in admin" link in the staff Telegram alert is built from the attacker's Host header. */
    public function test_finding_lead_alert_link_ignores_host_header(): void
    {
        config(['app.url' => 'https://bdb.uz', 'services.telegram.bot_token' => '123:FAKE', 'services.telegram.chat_id' => '-100']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->enable(['request_callback' => true]);

        $this->postJson('http://evil.example/mcp', $this->msg('request_callback', ['name' => 'Aziz Karimov', 'phone' => '+998901234567']))
            ->assertOk()->assertJsonPath('result.isError', false);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
        Http::assertNotSent(fn ($request) => str_contains((string) $request->body(), 'evil.example'));
    }

    /** F3: the Origin check compares with the request's own Host, so a rebinding attacker (Origin = Host) passes. */
    public function test_finding_origin_checked_against_app_url_not_host_header(): void
    {
        config(['app.url' => 'https://bdb.uz']);
        $this->enable();

        $this->postJson('http://rebind.example/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'http://rebind.example'])
            ->assertForbidden();
    }

    /** F4: wrong tokens are never throttled (the limiter runs only after authentication). */
    public function test_finding_failed_token_attempts_are_throttled(): void
    {
        McpSetting::current()->update(['enabled' => true, 'require_token' => true, 'rate_limit' => 5]);
        McpSetting::current()->regenerateToken();

        $statuses = [];
        for ($i = 0; $i < 30; $i++) {
            $statuses[] = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer guess'.$i])->status();
        }

        $this->assertContains(429, $statuses, '30 wrong tokens in a row were all answered 401');
    }

    /** F5: search_faq keeps serving knowledge entries after the admin turned the public FAQ off. */
    public function test_finding_search_faq_respects_faq_disabled(): void
    {
        $this->enable();
        KnowledgeEntry::create(['type' => 'faq', 'question' => ['en' => 'Do you deliver?'], 'answer' => ['en' => 'Yes, nationwide.'], 'is_active' => true]);
        SeoSetting::current()->fill(['faq_enabled' => false])->save();
        $this->get('/en/faq')->assertNotFound();

        $results = $this->postJson('/mcp', $this->msg('search_faq', ['query' => 'deliver']))->json('result.structuredContent.results');
        $this->assertSame([], $results ?? []);
    }

    // --- Controls that hold (expected to pass) ---------------------------------------------

    public function test_internal_knowledge_and_unpublished_articles_never_leave_mcp(): void
    {
        $this->enable();
        KnowledgeEntry::create(['type' => 'pricing', 'question' => ['en' => 'secret margin deliver'], 'answer' => ['en' => 'INTERNAL-42'], 'is_active' => true]);
        KnowledgeEntry::create(['type' => 'company', 'question' => ['en' => 'company deliver note'], 'answer' => ['en' => 'INTERNAL-43'], 'is_active' => true]);
        KnowledgeEntry::create(['type' => 'faq', 'question' => ['en' => 'inactive deliver'], 'answer' => ['en' => 'INACTIVE-44'], 'is_active' => false]);
        $category = ArticleCategory::create(['name' => ['en' => 'News'], 'slug' => ['en' => 'news']]);
        Article::create(['article_category_id' => $category->id, 'is_published' => false, 'title' => ['en' => 'Draft deliver'],
            'slug' => ['en' => 'draft-secret'], 'excerpt' => ['en' => 'x'], 'content' => ['en' => 'DRAFT-45']]);

        $body = $this->postJson('/mcp', [
            $this->msg('search_faq', ['query' => 'deliver secret margin company note inactive'], 1),
            $this->msg('list_articles', ['query' => 'draft'], 2),
            $this->msg('get_article', ['slug' => 'draft-secret'], 3),
            $this->msg('get_article', ['slug' => 'https://x/en/news/draft-secret'], 4),
        ])->assertOk()->getContent();

        foreach (['INTERNAL-42', 'INTERNAL-43', 'INACTIVE-44', 'DRAFT-45', 'Draft deliver'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_malformed_json_rpc_never_errors_500(): void
    {
        $this->enable();
        foreach (['', 'null', '[]', '"x"', '123', '[1,"a",null,[]]', '{"0":{"jsonrpc":"2.0","id":1,"method":"ping"}}',
            '{"jsonrpc":"2.0","id":{"a":1},"method":"ping"}', '{"jsonrpc":"2.0","id":1.5,"method":"ping"}',
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":["x"],"arguments":"y"}}',
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search_faq","arguments":{"query":"abc","limit":"1e99"}}}',
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_article","arguments":{"slug":"../../.env"}}}',
            str_repeat('[', 600).str_repeat(']', 600)] as $raw) {
            $status = $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->status();
            $this->assertLessThan(500, $status, "payload {$raw} caused {$status}");
        }

        $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', 100_001))->assertStatus(413);
    }

    public function test_disabled_tools_and_token_mode_hold(): void
    {
        $this->enable(['request_callback' => false, 'get_article' => false]);
        $this->postJson('/mcp', $this->msg('request_callback', ['name' => 'Aziz', 'phone' => '+998901234567']))->assertJsonPath('error.code', -32602);
        $this->postJson('/mcp', $this->msg('get_article', ['slug' => 'x']))->assertJsonPath('error.code', -32602);

        McpSetting::current()->update(['require_token' => true, 'token_hash' => null]);
        // Token mode with no token yet: nobody gets in, even with an empty or "null" bearer.
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer '])->assertUnauthorized();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer null'])->assertUnauthorized();
        $this->assertArrayNotHasKey('token_hash', McpSetting::current()->toArray());
    }

    public function test_seo_files_resist_host_header_and_admin_field_injection(): void
    {
        config(['app.url' => 'https://bdb.uz']);
        $admin = User::factory()->create(['is_admin' => true]);

        // Sitemap is cached: the first request's Host must not end up in it.
        $this->get('http://evil.example/sitemap.xml')->assertOk();
        $this->assertStringNotContainsString('evil.example', $this->get('/sitemap.xml')->getContent());
        $this->assertStringNotContainsString('evil.example', $this->get('http://evil.example/robots.txt')->getContent());

        $base = ['rate_limit' => 60, 'crawlers' => [], 'faq_types' => ['faq']];
        foreach ([
            ['robots_extra' => "Disallow: /x\r\nContent-Type: text/html"],
            ['robots_extra' => '<script>alert(1)</script>'],
            ['google_verification' => '"><script>alert(1)</script>'],
            ['yandex_verification' => "abc\nxyz"],
            ['faq_types' => ['pricing; drop']],
            ['opening_hours' => 'Mo-Fr 09:00-18:00"><script>'],
        ] as $bad) {
            $this->actingAs($admin)->put(route('admin.seo.update'), $bad + $base)->assertSessionHasErrors();
        }

        $this->actingAs($admin)->put(route('admin.seo.update'), $base + ['llms_summary' => '<script>x</script> Summary', 'llms_enabled' => 1])->assertSessionHasNoErrors();
        $this->get('/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertDontSee('<script>', false);

        // IndexNow key file answers only the real key.
        SeoSetting::current()->fill(['indexnow_enabled' => true])->save();
        $this->get('/aaaaaaaaaaaaaaaa.txt')->assertNotFound();
        $this->get('/'.SeoSetting::current()->indexNowKey().'.txt')->assertOk();
    }

    public function test_admin_settings_require_admin_and_ignore_mass_assignment(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        foreach ([['put', 'admin.seo.update'], ['put', 'admin.mcp.update'], ['post', 'admin.mcp.token'], ['post', 'admin.seo.indexnow']] as [$method, $route]) {
            $this->{$method}(route($route), [])->assertRedirect();
            $this->assertNotSame(200, $this->actingAs($user)->{$method}(route($route), [])->status());
            auth()->logout();
        }

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put(route('admin.mcp.update'), ['enabled' => 1, 'require_token' => 0, 'rate_limit' => 60,
            'token_hash' => str_repeat('a', 64), 'calls_count' => 999])->assertSessionHasNoErrors();
        $this->assertNull(McpSetting::current()->token_hash);
        $this->assertSame(0, McpSetting::current()->calls_count);

        $this->actingAs($admin)->put(route('admin.seo.update'), ['indexnow_key' => 'attackerkey123', 'indexnow_last_result' => '<b>x</b>'])->assertSessionHasNoErrors();
        $this->assertNotSame('attackerkey123', SeoSetting::current()->indexnow_key);
    }
}
