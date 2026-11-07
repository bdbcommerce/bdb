<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\KnowledgeEntry;
use App\Models\McpSetting;
use App\Models\PageView;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: site analytics edge cases and MCP team tools output for every channel / role. */
class QaAnalyticsTeamToolsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        $settings = McpSetting::current();
        $settings->update(['enabled' => true, 'require_token' => true, 'rate_limit' => 1000]);
        $this->token = $settings->regenerateToken();
    }

    private function tool(string $name, array $arguments = [])
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]],
            ['Authorization' => 'Bearer '.$this->token])->assertOk();
    }

    // --- Analytics -------------------------------------------------------------

    public function test_head_requests_and_referrer_variants(): void
    {
        $this->call('HEAD', '/en', [], [], [], ['HTTP_USER_AGENT' => self::CHROME]);
        $this->assertSame(0, PageView::count());

        $cases = [
            'https://www.google.co.uz/search?q=x' => ['search', 'google.co.uz'],
            'https://sub.chatgpt.com/' => ['ai', 'sub.chatgpt.com'],
            'https://gemini.google.com/app' => ['ai', 'gemini.google.com'],
            'https://WWW.LOCALHOST/uz' => ['internal', null],
            'https://example.org/' => ['other', 'example.org'],
            'not a url' => ['direct', null],
        ];
        foreach ($cases as $ref => $expected) {
            PageView::query()->delete();
            $this->get('/en', ['User-Agent' => self::CHROME, 'Referer' => $ref])->assertOk();
            $v = PageView::sole();
            $this->assertSame($expected, [$v->source, $v->referrer_host], $ref);
        }
    }

    public function test_bot_with_referrer_is_not_counted_as_source(): void
    {
        $this->get('/en', ['User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2)', 'Referer' => 'https://chatgpt.com/'])->assertOk();
        $v = PageView::sole();
        $this->assertSame(['GPTBot', true, 'direct', null], [$v->bot, $v->is_bot, $v->source, $v->device]);
    }

    public function test_page_in_all_periods_with_zero_days_and_50k_rows(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        // Empty table: every period renders, chart has one bar per day.
        foreach ([7, 30, 90, 365] as $d) {
            $res = $this->actingAs($admin)->get(route('admin.analytics', ['days' => $d]))->assertOk();
            $this->assertSame($d, $res->viewData('chart')->count());
            $this->assertSame(0, $res->viewData('totals')['views']);
        }

        $rows = [];
        $sources = ['direct', 'search', 'ai', 'social', 'other', 'internal'];
        for ($i = 0; $i < 50000; $i++) {
            $bot = $i % 10 === 0;
            $rows[] = [
                'visited_at' => now()->subMinutes($i * 10)->toDateTimeString(), 'path' => '/ru/p'.($i % 40), 'route' => 'site.about',
                'locale' => 'ru', 'referrer_host' => $i % 3 ? 'chatgpt.com' : null, 'source' => $sources[$i % 6],
                'device' => 'desktop', 'visitor_hash' => substr(md5((string) ($i % 500)), 0, 16), 'is_bot' => $bot, 'bot' => $bot ? 'GPTBot' : null,
            ];
            if (count($rows) === 1000) {
                DB::table('page_views')->insert($rows);
                $rows = [];
            }
        }

        $start = microtime(true);
        DB::enableQueryLog();
        $res = $this->actingAs($admin)->get(route('admin.analytics', ['days' => 365]))->assertOk();
        $elapsed = microtime(true) - $start;
        fwrite(STDERR, sprintf("\n[analytics 50k] %.0f ms, %d queries\n", $elapsed * 1000, count(DB::getQueryLog())));
        $this->assertLessThan(5, $elapsed);
        $this->assertGreaterThan(0, $res->viewData('totals')['visitors']);
        $this->assertSame(365, $res->viewData('chart')->count());
    }

    // --- Team tools ------------------------------------------------------------

    public function test_business_registration_phone_is_not_returned(): void
    {
        TelegramUser::create(['telegram_id' => '777', 'name' => 'Dilnoza', 'phone' => '+998901112233']);
        $c = ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'business', 'external_id' => '777', 'locale' => 'uz',
            'messages' => [
                ['role' => 'user', 'sender' => 'Dilnoza', 'content' => 'Salom, narxi qancha?'],
                ['role' => 'bot', 'sender' => 'BDB', 'content' => 'Ismingiz?'],
                ['role' => 'user', 'sender' => 'Dilnoza', 'content' => 'Dilnoza', 'aside' => true],
                ['role' => 'bot', 'sender' => 'BDB', 'content' => 'Telefon raqamingiz?'],
                ['role' => 'user', 'sender' => 'Dilnoza', 'content' => '+998 90 111 22 33', 'aside' => true], // typed during registration
                ['role' => 'owner', 'sender' => 'BDB Owner', 'content' => 'Rahmat!'],
            ],
            'message_count' => 6,
        ]);

        $body = $this->tool('get_conversation', ['id' => $c->id])->getContent();
        $this->assertStringNotContainsString('111 22 33', $body, 'Phone typed during Premium-account registration is returned by get_conversation');
    }

    public function test_every_role_and_message_kind(): void
    {
        $c = ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'group', 'external_id' => '-100', 'title' => 'Sales group', 'locale' => 'ru',
            'messages' => [
                ['role' => 'member', 'sender' => 'Иван', 'content' => 'Кто ответит?'],
                ['role' => 'admin', 'author' => 'Staff', 'content' => 'Я'],
                ['role' => 'user', 'type' => 'voice', 'file_id' => 'AwAC'],
                ['role' => 'user', 'type' => 'contact', 'file_id' => null],
                ['role' => 'user', 'content' => 'удалено', 'deleted' => true],
                ['role' => 'user', 'content' => 'исправлено', 'edited' => true],
                ['role' => 'user'], // broken entry: no content, no type
            ],
            'message_count' => 7,
        ]);
        $m = $this->tool('get_conversation', ['id' => $c->id])->json('result.structuredContent');
        $this->assertSame('Sales group', $m['customer']);
        $this->assertSame(['member', 'Иван'], [$m['messages'][0]['role'], $m['messages'][0]['sender']]);
        $this->assertSame('Staff', $m['messages'][1]['sender']);
        $this->assertSame('voice', $m['messages'][2]['attachment']);
        $this->assertSame('contact', $m['messages'][3]['attachment']);
        $this->assertTrue($m['messages'][4]['deleted']);
        $this->assertTrue($m['messages'][5]['edited']);
        $this->assertSame('other', $m['messages'][6]['attachment']);

        $web = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'web', 'locale' => 'en', 'messages' => [], 'message_count' => 0]);
        $this->assertSame('Website visitor', $this->tool('get_conversation', ['id' => $web->id])->json('result.structuredContent.customer'));
        $this->assertSame([], $this->tool('get_conversation', ['id' => $web->id])->json('result.structuredContent.messages'));
    }

    public function test_large_conversation_is_capped(): void
    {
        $messages = array_fill(0, 500, ['role' => 'user', 'content' => str_repeat('я', 4000)]);
        $c = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '1', 'locale' => 'ru', 'messages' => $messages, 'message_count' => 500]);
        $res = $this->tool('get_conversation', ['id' => $c->id]);
        $this->assertCount(200, $res->json('result.structuredContent.messages')); // latest 200, 2000 characters each
        fwrite(STDERR, sprintf("\n[get_conversation 500x4000] response %.1f MB\n", strlen($res->getContent()) / 1048576));
    }

    public function test_invalid_arguments_return_tool_errors(): void
    {
        $this->assertTrue($this->tool('get_conversation', ['id' => 'abc'])->json('result.isError'));
        $this->tool('list_conversations', ['limit' => -5, 'days' => 'x', 'channel' => 'nope'])->assertJsonPath('result.isError', false);
        $this->assertTrue($this->tool('save_knowledge_entry', ['type' => 'faq', 'question' => 'x', 'answer' => 'y'])->json('result.isError'));
        $tri = ['uz' => 'Savol', 'ru' => 'Вопрос', 'en' => 'Question'];
        $type = array_key_first(KnowledgeEntry::TYPES);
        $this->assertTrue($this->tool('save_knowledge_entry', ['type' => $type, 'question' => $tri, 'answer' => ['uz' => 'a', 'ru' => '[to fill: цена]', 'en' => 'c']])->json('result.isError'));
        $this->assertTrue($this->tool('save_knowledge_entry', ['type' => $type, 'question' => $tri, 'answer' => $tri, 'id' => 9999])->json('result.isError'));
        $this->assertTrue($this->tool('resolve_open_questions', ['request_id' => 1, 'knowledge_id' => 1])->json('result.isError'));
        $this->assertTrue($this->tool('add_ai_note', ['kind' => 'rules', 'title' => '', 'content' => 'x'])->json('result.isError'));
        $this->assertSame(0, KnowledgeEntry::count());
    }

    public function test_knowledge_link_rejects_non_http_schemes(): void
    {
        $tri = ['uz' => 'Savol', 'ru' => 'Вопрос', 'en' => 'Question'];
        $res = $this->tool('save_knowledge_entry', ['type' => array_key_first(KnowledgeEntry::TYPES), 'question' => $tri, 'answer' => $tri,
            'link' => 'javascript://x%0Aalert(1)']);
        $this->assertTrue((bool) $res->json('result.isError'), 'javascript: link accepted by save_knowledge_entry');
    }
}
