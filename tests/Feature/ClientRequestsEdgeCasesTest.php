<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ClientRequest;
use App\Models\KnowledgeEntry;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Database\Seeders\ArticleCategorySeeder;
use Database\Seeders\ArticleSeeder;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Edge cases for the client requests inbox, AI garbage handling, notifications and bot emoji.
 */
class ClientRequestsEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-key',
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function gemini(array|string $json): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => is_string($json) ? $json : json_encode($json)]]]]]];
    }

    /** Http::fake() stubs accumulate (first match wins), so start from a fresh client factory. */
    private function freshHttp(): void
    {
        Http::swap(new Factory(app('events')));
    }

    private function fakeGemini(array|string $json): void
    {
        $this->freshHttp();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->gemini($json)),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function request(array $attributes = []): ClientRequest
    {
        return ClientRequest::create($attributes + [
            'channel' => 'web', 'locale' => 'ru', 'question' => 'Доставляете в Самарканд?',
            'status' => 'open', 'topic' => 'delivery to samarkand', 'category' => 'delivery',
        ]);
    }

    private function entry(array $answer = ['uz' => 'Ha, 2 kunda.', 'ru' => 'Да, за 2 дня.', 'en' => 'Yes, in 2 days.']): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'type' => 'delivery', 'is_active' => true,
            'question' => ['uz' => 'Samarqandga yetkazasizmi?', 'ru' => 'Доставляете в Самарканд?', 'en' => 'Do you deliver to Samarkand?'],
            'answer' => $answer,
        ]);
    }

    private function chat(string $text = 'Delivery?', string $locale = 'en')
    {
        return $this->postJson('/chat/message', [
            'locale' => $locale, 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => $text]],
        ]);
    }

    private function entryForm(?ClientRequest $request, string $answerEn = 'Yes, in 2 days.'): array
    {
        return array_filter([
            'client_request_id' => $request?->id, 'type' => 'delivery', 'is_active' => 1,
            'question_uz' => 'Samarqandga yetkazasizmi?', 'question_ru' => 'Доставляете в Самарканд?', 'question_en' => 'Do you deliver to Samarkand?',
            'answer_uz' => 'Ha, 2 kunda.', 'answer_ru' => 'Да, за 2 дня.', 'answer_en' => $answerEn,
        ], fn ($v) => $v !== null);
    }

    private function registeredTelegramUser(string $id = '555', string $locale = 'en'): TelegramUser
    {
        return TelegramUser::create(['telegram_id' => $id, 'locale' => $locale, 'step' => 'done', 'name' => 'Aziz',
            'phone' => '+998901234567', 'registered_at' => now()]);
    }

    private function webhook(string $text, int $updateId = 1, int $chatId = 555)
    {
        return $this->postJson('/telegram/webhook', [
            'update_id' => $updateId,
            'message' => ['message_id' => $updateId, 'chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['id' => $chatId], 'text' => $text],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    // Pagination ---------------------------------------------------------------------

    public function test_inbox_paginates_groups_not_rows_and_keeps_filters(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            $this->request(['topic' => sprintf('topic number %02d', $i), 'channel' => 'telegram', 'question' => "Q{$i}"]);
        }
        // Three rows in one group: one list item, listed first (most asked).
        $this->request(['topic' => 'popular topic', 'channel' => 'telegram']);
        $this->request(['topic' => 'popular topic', 'channel' => 'telegram']);
        $this->request(['topic' => 'popular topic', 'channel' => 'telegram']);
        $admin = $this->admin();

        $page1 = $this->actingAs($admin)->get(route('admin.client-requests.index', ['channel' => 'telegram']))->assertOk();
        $page1->assertSeeInOrder(['Popular topic', 'Topic number']);
        $this->assertSame(30, $page1->viewData('groups')->count());
        $this->assertSame(36, $page1->viewData('groups')->total());
        $page1->assertSee('channel=telegram', false);

        $page2 = $this->actingAs($admin)->get(route('admin.client-requests.index', ['channel' => 'telegram', 'page' => 2]))->assertOk();
        $this->assertSame(6, $page2->viewData('groups')->count());

        $this->actingAs($admin)->get(route('admin.client-requests.index', ['page' => 99]))->assertOk()->assertSee('No requests here');
        $this->actingAs($admin)->get(route('admin.client-requests.index', ['page' => 'abc']))->assertOk();
    }

    public function test_requests_without_topic_are_separate_groups(): void
    {
        $a = $this->request(['topic' => null, 'question' => 'First untagged']);
        $b = $this->request(['topic' => null, 'question' => 'Second untagged']);

        $this->actingAs($this->admin())->get(route('admin.client-requests.index'))
            ->assertOk()->assertSee('First untagged')->assertSee('Second untagged');
        $this->assertSame(2, $this->actingAs($this->admin())->get(route('admin.client-requests.index'))->viewData('groups')->total());

        $this->actingAs($this->admin())->post(route('admin.client-requests.dismiss', $a));
        $this->assertSame('dismissed', $a->fresh()->status);
        $this->assertSame('open', $b->fresh()->status);
    }

    // AI garbage in chat replies -----------------------------------------------------

    public function test_reply_with_only_the_required_text_is_recorded(): void
    {
        $this->fakeGemini(['reply' => 'Hello']);

        $this->chat()->assertOk()->assertExactJson(['reply' => 'Hello', 'suggestions' => [], 'offer_callback' => false]);

        $r = ClientRequest::sole();
        $this->assertNull($r->topic);
        $this->assertSame('other', $r->category);
        $this->assertNull($r->knowledge_entry_id);
    }

    public function test_huge_and_messy_topic_is_normalised_and_cut(): void
    {
        $this->fakeGemini(['reply' => 'x', 'answered' => false, 'topic' => "  Zebra\n\nPRINTER   price!!! ".str_repeat('ы', 5000), 'category' => 'product']);

        $this->chat()->assertOk();

        $topic = ClientRequest::sole()->topic;
        $this->assertSame(120, mb_strlen($topic));
        $this->assertStringStartsWith('zebra printer price!!! ы', $topic);
    }

    public function test_non_integer_and_unknown_knowledge_ids_are_ignored(): void
    {
        $entry = $this->entry();

        foreach (['abc', '1x', -5, 0, 99999, 1.9] as $i => $garbage) {
            $this->fakeGemini(['reply' => 'x', 'knowledge_id' => $garbage, 'topic' => "t{$i}", 'category' => 'faq']);
            $this->chat("Q{$i}")->assertOk();
        }

        // Only "1x"/1.9 cast to 1 could match entry #1 — they must never point at a different entry.
        foreach (ClientRequest::all() as $r) {
            $this->assertTrue($r->knowledge_entry_id === null || $r->knowledge_entry_id === $entry->id);
        }
        $this->assertSame(6, ClientRequest::count());
    }

    public static function wrongTypes(): array
    {
        return [
            'topic array' => [['reply' => 'x', 'topic' => ['a', 'b'], 'category' => 'faq']],
            'category array' => [['reply' => 'x', 'topic' => 't', 'category' => ['faq']]],
            'knowledge_id array' => [['reply' => 'x', 'topic' => 't', 'knowledge_id' => [1]]],
            'topic and category numbers' => [['reply' => 'x', 'topic' => 12345, 'category' => 7]],
            'suggestions string' => [['reply' => 'x', 'suggestions' => 'one']],
            'reply array' => [['reply' => ['x']]],
        ];
    }

    #[DataProvider('wrongTypes')]
    public function test_wrong_json_types_from_the_ai_do_not_crash_the_chat(array $payload): void
    {
        $this->fakeGemini($payload);
        $this->chat()->assertOk()->assertJsonStructure(['reply', 'suggestions', 'offer_callback']);
    }

    public function test_gemini_timeout_and_invalid_json_fall_back_and_keep_the_widget_contract(): void
    {
        $this->freshHttp();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);
        $this->chat('Timeout question')->assertOk()
            ->assertExactJson(['reply' => __('chat.error', [], 'en'), 'suggestions' => [], 'offer_callback' => true]);

        $this->fakeGemini('not json at all');
        $this->chat('Broken json')->assertOk()->assertJsonStructure(['reply', 'suggestions', 'offer_callback'])
            ->assertJsonMissingPath('answered')->assertJsonMissingPath('topic');

        // The questions are kept as open requests so an admin can still answer them.
        $this->assertSame(['open', 'open'], ClientRequest::orderBy('id')->pluck('status')->all());
    }

    // Draft --------------------------------------------------------------------------

    public function test_draft_failure_shows_the_form_with_the_customer_question(): void
    {
        $request = $this->request(['locale' => 'ru']);
        $admin = $this->admin();

        $this->freshHttp();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('boom', 500)]);
        $this->actingAs($admin)->get(route('admin.knowledge.create', ['client_request' => $request->id, 'draft' => 1]))
            ->assertOk()->assertSee('could not draft')
            ->assertSee('name="question_ru"', false)->assertSee('value="Доставляете в Самарканд?"', false);

        $this->freshHttp();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);
        $this->actingAs($admin)->get(route('admin.knowledge.create', ['client_request' => $request->id, 'draft' => 1]))
            ->assertOk()->assertSee('could not draft');

        config(['services.gemini.key' => null]);
        $this->actingAs($admin)->get(route('admin.knowledge.create', ['client_request' => $request->id, 'draft' => 1]))
            ->assertOk()->assertSee('could not draft');
    }

    public function test_draft_with_missing_fields_does_not_crash(): void
    {
        $request = $this->request();
        $this->fakeGemini(['type' => 'delivery', 'answer_en' => 'Yes.']);

        $this->actingAs($this->admin())->get(route('admin.knowledge.create', ['client_request' => $request->id, 'draft' => 1]))
            ->assertOk();
    }

    public function test_plain_create_edit_and_update_still_work(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.knowledge.create'))->assertOk()->assertDontSee('Answering customer questions');
        $this->actingAs($admin)->get(route('admin.knowledge.create', ['client_request' => 99999]))->assertOk()->assertDontSee('Answering customer questions');

        $this->actingAs($admin)->post(route('admin.knowledge.store'), $this->entryForm(null))->assertRedirect(route('admin.knowledge.index'));
        $entry = KnowledgeEntry::sole();

        $this->actingAs($admin)->get(route('admin.knowledge.edit', $entry))->assertOk()
            ->assertSee('value="Samarqandga yetkazasizmi?"', false)->assertSee('Да, за 2 дня.')->assertDontSee('Answering customer questions');

        $this->actingAs($admin)->put(route('admin.knowledge.update', $entry), $this->entryForm(null, 'Yes, in 3 days.'))->assertRedirect();
        $this->assertSame('Yes, in 3 days.', $entry->fresh()->getTranslation('answer', 'en'));

        $this->actingAs($admin)->put(route('admin.knowledge.update', $entry), $this->entryForm(null, 'In [to fill: days] days'))
            ->assertSessionHasErrors('answer_en');
        $this->assertSame('Yes, in 3 days.', $entry->fresh()->getTranslation('answer', 'en'));
    }

    public function test_invalid_answer_keeps_old_input_and_client_request_link(): void
    {
        $request = $this->request();
        $admin = $this->admin();
        $from = route('admin.knowledge.create', ['client_request' => $request->id]);

        $this->actingAs($admin)->from($from)->post(route('admin.knowledge.store'), $this->entryForm($request, 'Yes [TO FILL: x]'))
            ->assertRedirect($from)->assertSessionHasErrors('answer_en')->assertSessionHasInput('answer_ru', 'Да, за 2 дня.');

        $this->actingAs($admin)->withSession(['_old_input' => $this->entryForm($request, 'Yes [TO FILL: x]')])->get($from)
            ->assertOk()->assertSee('Да, за 2 дня.')->assertSee('name="client_request_id" value="'.$request->id.'"', false);
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_validation_error_after_an_ai_draft_does_not_request_a_new_draft(): void
    {
        $request = $this->request();
        $admin = $this->admin();
        $this->fakeGemini([
            'type' => 'delivery', 'keywords' => 'k', 'question_uz' => 'q', 'question_ru' => 'q', 'question_en' => 'q',
            'answer_uz' => 'a [TO FILL: x]', 'answer_ru' => 'a', 'answer_en' => 'a',
        ]);
        $draftUrl = route('admin.knowledge.create', ['client_request' => $request->id, 'draft' => 1]);

        $this->actingAs($admin)->get($draftUrl)->assertOk();
        $response = $this->actingAs($admin)->from($draftUrl)->post(route('admin.knowledge.store'), $this->entryForm($request, 'Yes [TO FILL: x]'));
        $response->assertSessionHasErrors('answer_en');

        // Following the redirect back must not call Gemini again (the admin's edits are in old input anyway).
        $this->actingAs($admin)->get($response->headers->get('Location'))->assertOk();
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'generateContent')));
    }

    // Matching -----------------------------------------------------------------------

    public function test_ai_matches_only_offered_open_requests_whatever_it_returns(): void
    {
        $request = $this->request();
        $open = $this->request(['topic' => 'shipping', 'question' => 'Ship to Bukhara?']);
        $otherEntry = $this->entry();
        $resolvedElsewhere = $this->request(['topic' => 'other', 'status' => 'resolved', 'knowledge_entry_id' => $otherEntry->id]);
        $dismissed = $this->request(['topic' => 'spam', 'status' => 'dismissed']);
        $answered = $this->request(['topic' => 'asl belgi', 'status' => 'answered']);

        $this->fakeGemini(['answered_ids' => [
            (string) $open->id, $resolvedElsewhere->id, $dismissed->id, $answered->id, 'abc', [$dismissed->id], -1, null,
        ]]);

        $this->actingAs($this->admin())->post(route('admin.knowledge.store'), $this->entryForm($request))->assertRedirect();

        $new = KnowledgeEntry::latest('id')->first();
        $this->assertSame('resolved', $open->fresh()->status);
        $this->assertSame($new->id, $open->fresh()->knowledge_entry_id);
        $this->assertSame($otherEntry->id, $resolvedElsewhere->fresh()->knowledge_entry_id);
        $this->assertSame('dismissed', $dismissed->fresh()->status);
        $this->assertSame('answered', $answered->fresh()->status);

        Http::assertSent(function (Request $r) use ($resolvedElsewhere, $dismissed, $answered) {
            $p = $r['system_instruction']['parts'][0]['text'];

            return str_contains($p, 'Customer questions')
                && ! str_contains($p, "#{$resolvedElsewhere->id}:") && ! str_contains($p, "#{$dismissed->id}:") && ! str_contains($p, "#{$answered->id}:");
        });
    }

    public function test_matching_failure_still_saves_and_resolves_the_group(): void
    {
        $request = $this->request();
        $this->request(['topic' => 'shipping']);
        $this->freshHttp();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);

        $this->actingAs($this->admin())->post(route('admin.knowledge.store'), $this->entryForm($request))
            ->assertRedirect(route('admin.client-requests.show', $request))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Answered 1 request in this group.'));
        $this->assertSame('resolved', $request->fresh()->status);
        $this->assertSame(1, ClientRequest::open()->count());

        $this->fakeGemini(['answered_ids' => 'garbage']);
        $r2 = $this->request(['topic' => 'third']);
        $this->actingAs($this->admin())->post(route('admin.knowledge.store'), $this->entryForm($r2))->assertRedirect();
        $this->assertSame(1, ClientRequest::open()->count());
    }

    // Notify -------------------------------------------------------------------------

    public function test_notify_failures_are_counted_and_retried_only_for_unreached_customers(): void
    {
        $entry = $this->entry();
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => 'done', 'registered_at' => now()]);
        // 777 has no TelegramUser row: falls back to the request locale.
        $ok = $this->request(['channel' => 'telegram', 'external_id' => '555', 'locale' => 'uz', 'status' => 'resolved', 'knowledge_entry_id' => $entry->id]);
        $blocked = $this->request(['channel' => 'telegram', 'external_id' => '777', 'locale' => 'uz', 'status' => 'resolved', 'knowledge_entry_id' => $entry->id]);

        $blocked777 = true;
        Http::fake(['api.telegram.org/*' => function (Request $r) use (&$blocked777) {
            return $blocked777 && (string) $r['chat_id'] === '777'
            ? Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)
            : Http::response(['ok' => true, 'result' => []]);
        }]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.client-requests.notify', $ok), ['knowledge_entry_id' => $entry->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'sent to 1 Telegram customer.') && str_contains($m, '1 could not be reached'));

        $sent = Http::recorded()->map(fn ($p) => $p[0]);
        // The customer switched the bot to Russian after asking in Uzbek: the answer goes out in Russian.
        $this->assertStringContainsString('Да, за 2 дня.', $sent->first(fn ($r) => (string) $r['chat_id'] === '555')['text']);
        $this->assertStringContainsString('Ha, 2 kunda.', $sent->first(fn ($r) => (string) $r['chat_id'] === '777')['text']);
        $this->assertNotNull($ok->fresh()->notified_at);
        $this->assertNull($blocked->fresh()->notified_at);

        $blocked777 = false;
        $before = Http::recorded()->count();
        $this->actingAs($admin)->post(route('admin.client-requests.notify', $ok), ['knowledge_entry_id' => $entry->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'sent to 1 Telegram customer.'));
        $this->assertSame(['777'], Http::recorded()->slice($before)->map(fn ($p) => (string) $p[0]['chat_id'])->values()->all());
        $this->assertNotNull($blocked->fresh()->notified_at);
    }

    public function test_notify_without_a_bot_token_reports_failures_and_sends_nothing(): void
    {
        config(['services.telegram.bot_token' => null]);
        Http::fake();
        $entry = $this->entry();
        $r = $this->request(['channel' => 'telegram', 'external_id' => '555', 'status' => 'resolved', 'knowledge_entry_id' => $entry->id]);

        $this->actingAs($this->admin())->post(route('admin.client-requests.notify', $r), ['knowledge_entry_id' => $entry->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'sent to 0') && str_contains($m, '1 could not be reached'));
        Http::assertNothingSent();
        $this->assertNull($r->fresh()->notified_at);
    }

    public function test_notify_requires_a_valid_entry(): void
    {
        $r = $this->request();
        $this->actingAs($this->admin())->post(route('admin.client-requests.notify', $r), ['knowledge_entry_id' => 'x'])->assertSessionHasErrors('knowledge_entry_id');
        $this->actingAs($this->admin())->post(route('admin.client-requests.notify', $r), [])->assertSessionHasErrors('knowledge_entry_id');
    }

    // Access control -----------------------------------------------------------------

    public function test_all_new_routes_refuse_guests_and_non_admins(): void
    {
        $entry = $this->entry();
        $request = $this->request(['channel' => 'telegram', 'external_id' => '555', 'status' => 'resolved', 'knowledge_entry_id' => $entry->id]);
        $open = $this->request();
        $conversation = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        Http::fake();
        $user = User::factory()->create(['is_admin' => false]);

        $routes = [
            ['get', route('admin.client-requests.index')],
            ['get', route('admin.client-requests.show', $request)],
            ['post', route('admin.client-requests.link', $open), ['knowledge_entry_id' => $entry->id]],
            ['post', route('admin.client-requests.dismiss', $open)],
            ['post', route('admin.client-requests.notify', $request), ['knowledge_entry_id' => $entry->id]],
            ['post', route('admin.chat-conversations.suggest', $conversation)],
            ['get', route('admin.knowledge.create', ['client_request' => $open->id, 'draft' => 1])],
            ['post', route('admin.knowledge.store'), $this->entryForm($open)],
        ];

        foreach ([null, $user] as $who) {
            foreach ($routes as $route) {
                [$method, $url, $data] = $route + [2 => []];
                $who ? $this->actingAs($who) : auth()->logout();
                $this->{$method}($url, $data)->assertRedirect(route('admin.login'));
            }
            $who ? $this->actingAs($who) : auth()->logout();
            $this->postJson(route('admin.chat-conversations.suggest', $conversation))->assertForbidden();
        }

        $this->assertSame('open', $open->fresh()->status);
        $this->assertNull($request->fresh()->notified_at);
        $this->assertSame(1, KnowledgeEntry::count());
        Http::assertNothingSent();
    }

    public function test_new_admin_routes_are_not_csrf_exempt(): void
    {
        $this->assertTrue((bool) collect(app('router')->getRoutes()->getRoutesByName())
            ->only(['admin.client-requests.link', 'admin.client-requests.dismiss', 'admin.client-requests.notify', 'admin.chat-conversations.suggest'])
            ->every(fn ($route) => in_array('web', $route->gatherMiddleware(), true)));

        $middleware = app(ValidateCsrfToken::class);
        $except = (fn () => $this->getExcludedPaths())->call($middleware);
        $this->assertSame([], array_values(array_filter($except, fn ($p) => str_starts_with($p, 'admin'))));
    }

    // XSS ----------------------------------------------------------------------------

    public function test_customer_and_ai_text_is_escaped_in_admin_views(): void
    {
        $xss = '<script>alert(1)</script>';
        $r = $this->request([
            'question' => "Question {$xss}", 'topic' => '<img src=x onerror=alert(2)>',
            'ai_reply' => "Reply {$xss}",
        ]);
        $admin = $this->admin();

        foreach ([
            route('admin.client-requests.index'),
            route('admin.client-requests.show', $r),
            route('admin.knowledge.create', ['client_request' => $r->id]),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertDontSee($xss, false)->assertDontSee('<img src=x onerror', false);
        }

        $this->fakeGemini(['reply' => "Draft {$xss}"]);
        $conversation = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => "Hi {$xss}"]]]);
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $conversation))->assertOk()->assertDontSee($xss, false);
        // JSON is inserted with x-model (textarea value), never as HTML.
        $this->actingAs($admin)->postJson(route('admin.chat-conversations.suggest', $conversation))->assertOk();

        $old = "O'Reilly \"quoted\" {$xss}";
        $this->actingAs($admin)->withSession(['_old_input' => ['message' => $old]])
            ->get(route('admin.chat-conversations.show', $conversation))->assertOk()->assertDontSee($xss, false);
    }

    public function test_loosely_typed_knowledge_ids_do_not_link_an_entry(): void
    {
        $entry = $this->entry();

        foreach (['1x', [1], 1.9, true] as $i => $garbage) {
            $this->fakeGemini(['reply' => 'x', 'knowledge_id' => $garbage, 'topic' => "t{$i}", 'category' => 'faq']);
            $this->chat("Q{$i}")->assertOk();
        }

        $this->assertSame([], ClientRequest::whereNotNull('knowledge_entry_id')->pluck('topic')->all(), "entry #{$entry->id} linked from a non-integer id");
    }

    public function test_prompt_size_with_seeded_demo_content(): void
    {
        $this->seed([ArticleCategorySeeder::class, DemoContentSeeder::class]);
        $this->fakeGemini(['reply' => 'ok']);
        $sizes = [];

        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->chat('Hi', $locale)->assertOk();
            $prompt = Http::recorded()->last()[0]['system_instruction']['parts'][0]['text'];
            $sizes[$locale] = mb_strlen($prompt);
            $this->assertStringContainsString('## Articles', $prompt);
            $this->assertSame(20, substr_count($prompt, 'Link: '.url("/{$locale}/news/")), "article links {$locale}");
            $this->assertDoesNotMatchRegularExpression("/<\/?(p|b)>/", $prompt);
        }

        // Measured at QA: uz ~26.5k, ru ~25.0k, en ~24.2k characters with the demo content and 20 articles.
        foreach ($sizes as $size) {
            $this->assertLessThan(60000, $size);
        }
    }

    public function test_news_pages_with_seeded_articles_have_no_raw_translation_keys(): void
    {
        $this->seed([ArticleCategorySeeder::class, ArticleSeeder::class]);

        foreach (['uz', 'ru', 'en'] as $locale) {
            $pages = [route('site.news', ['locale' => $locale])];
            foreach (ArticleCategory::all() as $category) {
                $pages[] = route('site.news.category', ['locale' => $locale, 'categorySlug' => $category->getTranslation('slug', $locale)]);
            }
            foreach (Article::with('category')->get() as $article) {
                $pages[] = route('site.news.show', ['locale' => $locale,
                    'categorySlug' => $article->category->getTranslation('slug', $locale), 'slug' => $article->getTranslation('slug', $locale)]);
            }

            foreach ($pages as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertDoesNotMatchRegularExpression('/>\s*(site|chat)\.[a-z_]+(\.[a-z_]+)*\s*</', $html, $url);
                $this->assertStringNotContainsString('<script>alert', $html);
            }
        }
    }

    // Bot emoji ----------------------------------------------------------------------

    public function test_without_emoji_strips_button_decorations_only(): void
    {
        $this->assertSame('Oʻzbekcha', TelegramBot::withoutEmoji('🇺🇿 Oʻzbekcha'));
        $this->assertSame('Request a call', TelegramBot::withoutEmoji('📞 Request a call'));
        $this->assertSame('What is Asl Belgi?', TelegramBot::withoutEmoji('💡 What is Asl Belgi?'));
        $this->assertSame('Mutaxassis', TelegramBot::withoutEmoji('👨‍💼 Mutaxassis 👍🏽'));
        $this->assertSame('Price of 2 🖨 printers?', TelegramBot::withoutEmoji('Price of 2 🖨 printers?'));
        $this->assertSame('', TelegramBot::withoutEmoji('🙂🙂'));
        $this->assertSame('Привет', TelegramBot::withoutEmoji("  Привет \n"));
    }

    public function test_emoji_buttons_still_work_end_to_end(): void
    {
        $this->fakeGemini(['reply' => 'OK', 'suggestions' => ['What is Asl Belgi?'], 'offer_callback' => true, 'answered' => true, 'knowledge_id' => 0, 'topic' => 'asl belgi', 'category' => 'asl_belgi']);

        // Language picker with flags.
        $this->webhook('/start', 1)->assertOk();
        $this->webhook(TelegramBot::LANGUAGES['ru'], 2)->assertOk();
        $this->assertSame('ru', TelegramUser::where('telegram_id', '555')->value('locale'));
        // Typed without the flag also works.
        $this->webhook('/language', 3)->assertOk();
        $this->webhook('English', 4)->assertOk();
        $this->assertSame('en', TelegramUser::where('telegram_id', '555')->value('locale'));

        TelegramUser::where('telegram_id', '555')->update(['step' => 'done', 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);

        // A 💡 suggestion button reaches the AI without the emoji, and is recorded without it.
        $this->webhook('💡 What is Asl Belgi?', 5)->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateContent')
            && collect($r['contents'])->last()['parts'][0]['text'] === 'What is Asl Belgi?');
        $this->assertSame('What is Asl Belgi?', ClientRequest::latest('id')->value('question'));

        // The keyboard shows the suggestion with the 💡 prefix and the call button with its emoji.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r['reply_markup'] ?? [], JSON_UNESCAPED_UNICODE), '💡 What is Asl Belgi?'));

        $this->webhook(__('chat.tg_request_call', [], 'en'), 6)->assertOk();
        $this->assertSame(1, ChatLead::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && ($r['text'] ?? '') === __('chat.tg_thanks', [], 'en'));
    }

    public function test_lang_files_have_identical_chat_keys(): void
    {
        $keys = fn (string $l) => array_keys(require lang_path("{$l}/chat.php"));
        $this->assertSame($keys('en'), $keys('uz'));
        $this->assertSame($keys('en'), $keys('ru'));
        foreach (['uz', 'ru', 'en'] as $l) {
            $this->assertNotSame('chat.tg_welcome', __('chat.tg_welcome', [], $l));
            $this->assertNotSame('chat.tg_thanks', __('chat.tg_thanks', [], $l));
            $this->assertNotSame('chat.tg_followup', __('chat.tg_followup', [], $l));
        }
    }
}
