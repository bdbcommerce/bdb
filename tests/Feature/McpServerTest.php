<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Brand;
use App\Models\ChatLead;
use App\Models\CompanyInfo;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use App\Models\McpSetting;
use App\Models\SeoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** MCP server at /mcp (JSON-RPC over Streamable HTTP) and its admin settings. */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private ?string $token = null;

    private function enable(bool $requireToken = true, array $tools = []): void
    {
        SeoSetting::current()->update(['faq_enabled' => true]); // search_faq follows the public FAQ
        $settings = McpSetting::current();
        $settings->update(['enabled' => true, 'require_token' => $requireToken, 'tools' => $tools ?: null]);
        $this->token = $requireToken ? $settings->regenerateToken() : null;
    }

    private function rpc(string $method, array $params = [], int|string|null $id = 1, array $headers = [])
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method] + ($params ? ['params' => $params] : []) + ($id === null ? [] : ['id' => $id]);

        return $this->postJson('/mcp', $message, $headers + ($this->token ? ['Authorization' => 'Bearer '.$this->token] : []));
    }

    private function tool(string $tool, array $arguments = [])
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    public function test_off_by_default_and_token_protected(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertNotFound();

        $this->enable();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->rpc('ping')->assertOk()->assertJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        // Browser pages from other sites are refused (DNS rebinding).
        $this->rpc('ping', headers: ['Origin' => 'https://evil.example'])->assertForbidden();
        $this->get('/mcp')->assertStatus(405);
    }

    public function test_protocol_handshake_and_tool_list(): void
    {
        $this->enable(requireToken: false, tools: ['request_callback' => false] + array_fill_keys(['get_company_info', 'list_brands', 'list_industries', 'search_faq', 'list_articles', 'get_article'], true));

        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'claude', 'version' => '1']])
            ->assertOk()->assertHeader('Mcp-Session-Id')
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'bdb-auto-id')
            ->assertJsonPath('result.capabilities.tools.listChanged', false);
        $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])->assertJsonPath('result.protocolVersion', '2025-06-18');

        $this->rpc('notifications/initialized', id: null)->assertStatus(202);

        $tools = collect($this->rpc('tools/list')->assertOk()->json('result.tools'));
        $this->assertSame(['get_company_info', 'list_brands', 'list_industries', 'search_faq', 'list_articles', 'get_article'], $tools->pluck('name')->all());
        $this->assertSame(['query'], $tools->firstWhere('name', 'search_faq')['inputSchema']['required']);

        $this->rpc('nope')->assertJsonPath('error.code', -32601);
        $this->tool('request_callback', ['name' => 'Aziz', 'phone' => '+998901234567'])->assertJsonPath('error.code', -32602); // disabled
        $this->postJson('/mcp', ['jsonrpc' => '1.0', 'id' => 2, 'method' => 'ping'])->assertJsonPath('error.code', -32600);
        $this->tool('', [])->assertJsonPath('error.code', -32602);
        $this->withHeaders(['Content-Type' => 'application/json'])->call('POST', '/mcp', [], [], [], [], '{bad json')->assertStatus(400)->assertJsonPath('error.code', -32700);
    }

    public function test_tools_return_public_content(): void
    {
        $this->enable(requireToken: false);
        CompanyInfo::instance()->update(['phone' => '+998 71 200 00 00', 'about' => ['ru' => 'BDB — оборудование для штрихкодов.'], 'address' => ['ru' => 'Ташкент']]);
        Brand::create(['name' => ['en' => 'Zebra'], 'description' => ['en' => 'Printers'], 'is_active' => true]);
        $retail = Industry::create(['name' => ['ru' => 'Розница'], 'icon' => 'shopping-cart', 'is_active' => true]);
        Customer::create(['name' => 'Korzinka', 'industry_id' => $retail->id, 'is_active' => true]);
        KnowledgeEntry::create(['type' => 'faq', 'question' => ['ru' => 'Есть ли доставка по Узбекистану?'], 'answer' => ['ru' => 'Да, доставляем по всей стране.'], 'is_active' => true]);
        KnowledgeEntry::create(['type' => 'pricing', 'question' => ['ru' => 'Доставка: внутренняя наценка'], 'answer' => ['ru' => 'секрет'], 'is_active' => true]);
        $category = ArticleCategory::create(['name' => ['ru' => 'Новости'], 'slug' => ['ru' => 'novosti', 'en' => 'news']]);
        Article::create(['article_category_id' => $category->id, 'is_published' => true, 'published_at' => now(),
            'title' => ['ru' => 'Что такое Asl Belgi'], 'slug' => ['ru' => 'asl-belgi'], 'excerpt' => ['ru' => 'Маркировка'], 'content' => ['ru' => '<p>Полный <b>текст</b></p>']]);

        $info = $this->tool('get_company_info', ['locale' => 'ru'])->assertOk()->json('result');
        $this->assertFalse($info['isError']);
        $this->assertSame(['+998 71 200 00 00', 'Ташкент'], [$info['structuredContent']['phone'], $info['structuredContent']['address']]);
        $this->assertStringContainsString('BDB — оборудование', $info['content'][0]['text']);

        $this->assertSame('Zebra', $this->tool('list_brands')->json('result.structuredContent.brands.0.name'));
        $this->assertSame(['Розница', 1], array_values(array_intersect_key($this->tool('list_industries', ['locale' => 'ru'])->json('result.structuredContent.industries.0'), ['name' => 1, 'customers' => 1])));
        $this->assertStringNotContainsString('Korzinka', $this->tool('list_industries')->getContent()); // no customer names

        $faq = $this->tool('search_faq', ['query' => 'доставка Узбекистан', 'locale' => 'ru'])->json('result.structuredContent.results');
        $this->assertCount(1, $faq); // the pricing (internal) entry is not searchable
        $this->assertSame('Да, доставляем по всей стране.', $faq[0]['answer']);
        $this->assertTrue($this->tool('search_faq', [])->json('result.isError'));
        $this->assertTrue($this->tool('search_faq', ['query' => ['x']])->json('result.isError'));

        $articles = $this->tool('list_articles', ['locale' => 'ru', 'query' => 'asl'])->json('result.structuredContent.articles');
        $this->assertSame(route('site.news.show', ['ru', 'novosti', 'asl-belgi']), $articles[0]['url']);
        $this->assertSame('Полный текст', $this->tool('get_article', ['slug' => $articles[0]['url'], 'locale' => 'ru'])->json('result.structuredContent.text'));
        $this->assertTrue($this->tool('get_article', ['slug' => 'missing'])->json('result.isError'));
    }

    public function test_request_callback_creates_a_lead_when_enabled(): void
    {
        Http::fake();
        $this->enable(requireToken: true, tools: ['request_callback' => true]);

        $this->tool('request_callback', ['name' => 'Aziz Karimov', 'phone' => '90 123 45 67', 'topic' => 'Label printer'])
            ->assertOk()->assertJsonPath('result.isError', false);
        $lead = ChatLead::sole();
        $this->assertSame(['mcp', 'Aziz Karimov', '+998901234567', 'Label printer'], [$lead->channel, $lead->name, $lead->phone, $lead->topic]);

        $this->assertTrue($this->tool('request_callback', ['name' => '<script>', 'phone' => '+998901234567'])->json('result.isError'));
        $this->assertTrue($this->tool('request_callback', ['name' => 'Aziz', 'phone' => 'call me'])->json('result.isError'));
    }

    public function test_batches_and_rate_limit(): void
    {
        $this->enable(requireToken: false);
        McpSetting::current()->update(['rate_limit' => 5]);

        $batch = $this->postJson('/mcp', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ])->assertOk()->json();
        $this->assertSame([1, 2], array_column($batch, 'id'));

        for ($i = 0; $i < 2; $i++) { // the batch already used 3 of the 5
            $this->rpc('ping')->assertOk();
        }
        $this->rpc('ping')->assertStatus(429);
        $this->assertGreaterThan(0, McpSetting::current()->calls_count);
    }

    public function test_admin_page_settings_and_token(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.mcp.edit'))->assertOk()->assertSee('MCP server')->assertSee('claude mcp add --transport http bdb');

        $this->actingAs($admin)->put(route('admin.mcp.update'), [
            'enabled' => '1', 'require_token' => '1', 'rate_limit' => 60, 'tools' => ['get_company_info' => '1', 'list_brands' => '0'],
        ])->assertSessionHas('mcp_token');
        $token = session('mcp_token');
        $settings = McpSetting::current();
        $this->assertTrue($settings->enabled && $settings->tokenMatches($token));
        $this->assertNotSame($token, $settings->token_hash); // stored hashed
        $this->assertSame(['get_company_info'], $settings->enabledTools()); // the form sends every tool; unsent = off

        $this->actingAs($admin)->get(route('admin.mcp.edit'))->assertOk()->assertSee($token); // shown once, right after creation
        $this->actingAs($admin)->get(route('admin.mcp.edit'))->assertOk()->assertDontSee($token);
        $this->actingAs($admin)->post(route('admin.mcp.token'))->assertSessionHas('mcp_token');
        $this->assertFalse(McpSetting::current()->tokenMatches($token)); // old token revoked

        $this->actingAs($admin)->put(route('admin.mcp.update'), ['rate_limit' => 1])->assertSessionHasErrors('rate_limit');

        auth()->logout();
        $this->get(route('admin.mcp.edit'))->assertRedirect(route('admin.login'));
    }
}
