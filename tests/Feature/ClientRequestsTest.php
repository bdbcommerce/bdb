<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\KnowledgeEntry;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-key',
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.webhook_secret' => 'test_webhook_secret_123',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** Fake Gemini: the first matching key in $answers (by a string the request contains) wins. */
    private function fakeGemini(array $json, array $telegram = ['ok' => true, 'result' => []]): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]],
            ]),
            'api.telegram.org/*' => Http::response($telegram),
        ]);
    }

    private function aiReply(array $overrides = []): array
    {
        return $overrides + [
            'reply' => 'I don\'t have that information.', 'suggestions' => [], 'offer_callback' => true,
            'answered' => false, 'knowledge_id' => 0, 'topic' => 'Delivery to Samarkand', 'category' => 'delivery',
        ];
    }

    private function request(array $attributes = []): ClientRequest
    {
        return ClientRequest::create($attributes + [
            'channel' => 'web', 'locale' => 'ru', 'question' => 'Доставляете в Самарканд?',
            'status' => 'open', 'topic' => 'delivery to samarkand', 'category' => 'delivery',
        ]);
    }

    private function entry(): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'type' => 'delivery', 'is_active' => true,
            'question' => ['uz' => 'Samarqandga yetkazasizmi?', 'ru' => 'Доставляете в Самарканд?', 'en' => 'Do you deliver to Samarkand?'],
            'answer' => ['uz' => 'Ha, 2 kunda.', 'ru' => 'Да, за 2 дня.', 'en' => 'Yes, in 2 days.'],
        ]);
    }

    // Recording ----------------------------------------------------------------------

    public function test_website_questions_are_saved_with_ai_tags_and_tags_stay_internal(): void
    {
        $entry = $this->entry();
        $this->fakeGemini($this->aiReply(['answered' => true, 'knowledge_id' => $entry->id, 'reply' => 'Да, за 2 дня.']));

        $this->postJson('/chat/message', [
            'locale' => 'ru', 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => 'Доставка в Самарканд есть?']],
        ])->assertOk()->assertJsonMissingPath('topic')->assertJsonMissingPath('knowledge_id')->assertJsonMissingPath('category');

        $request = ClientRequest::sole();
        $this->assertSame('answered', $request->status);
        $this->assertSame('delivery to samarkand', $request->topic);
        $this->assertSame($entry->id, $request->knowledge_entry_id);
        $this->assertSame('web', $request->channel);
        $this->assertNotNull($request->chat_conversation_id);

        Http::assertSent(fn (Request $r) => str_contains($r['system_instruction']['parts'][0]['text'], "Q [#{$entry->id}]:"));
    }

    public function test_unanswered_questions_are_open_and_greetings_are_not_saved(): void
    {
        $gemini = fn (array $json) => ['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]]];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($gemini($this->aiReply()))
            ->push($gemini($this->aiReply(['answered' => true, 'category' => 'greeting', 'topic' => 'greeting'])))]);

        $this->postJson('/chat/message', ['locale' => 'ru', 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => 'Доставка в Самарканд?']]])->assertOk();
        $this->assertSame('open', ClientRequest::sole()->status);

        $this->postJson('/chat/message', ['locale' => 'ru', 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => 'Привет!']]])->assertOk();
        $this->assertSame(1, ClientRequest::count());
    }

    public function test_unknown_knowledge_id_and_category_are_not_trusted(): void
    {
        $this->fakeGemini($this->aiReply(['knowledge_id' => 999, 'category' => 'made_up']));

        $this->postJson('/chat/message', ['locale' => 'en', 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => 'Delivery?']]])->assertOk();

        $request = ClientRequest::sole();
        $this->assertNull($request->knowledge_entry_id);
        $this->assertSame('other', $request->category);
    }

    public function test_telegram_questions_are_saved_with_the_chat_id(): void
    {
        $this->fakeGemini($this->aiReply());
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => 'done', 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);

        $this->postJson('/telegram/webhook', [
            'update_id' => 1,
            'message' => ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555], 'text' => 'Доставка в Самарканд?'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test_webhook_secret_123'])->assertOk();

        $request = ClientRequest::sole();
        $this->assertSame('telegram', $request->channel);
        $this->assertSame('555', $request->external_id);
        $this->assertSame('open', $request->status);
    }

    // Inbox --------------------------------------------------------------------------

    public function test_inbox_groups_open_requests_by_topic_most_asked_first(): void
    {
        $this->request(['question' => 'Доставляете в Самарканд?']);
        $this->request(['question' => 'Samarqandga yetkazib berasizmi?', 'locale' => 'uz', 'channel' => 'telegram']);
        $this->request(['question' => 'Is there a Zebra ZT411 in stock?', 'topic' => 'zebra zt411 stock', 'locale' => 'en']);
        $this->request(['question' => 'Answered one', 'topic' => 'what is asl belgi', 'status' => 'answered']);

        $this->actingAs($this->admin())->get(route('admin.client-requests.index'))
            ->assertOk()
            ->assertSeeInOrder(['Delivery to samarkand', 'Zebra zt411 stock'])
            ->assertSee('Samarqandga yetkazib berasizmi?')
            ->assertDontSee('What is asl belgi');

        $this->actingAs($this->admin())->get(route('admin.client-requests.index', ['status' => 'answered']))
            ->assertOk()->assertSee('What is asl belgi')->assertDontSee('Delivery to samarkand');

        $this->actingAs($this->admin())->get(route('admin.client-requests.index', ['channel' => 'telegram']))
            ->assertOk()->assertSee('Delivery to samarkand')->assertDontSee('Zebra zt411 stock');

        $this->actingAs($this->admin())->get(route('admin.client-requests.index', ['status' => ['x'], 'channel' => ['y']]))->assertOk();
    }

    public function test_sidebar_badge_counts_open_requests(): void
    {
        $this->request();
        $this->request();
        $this->request(['status' => 'answered']);

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()->assertSeeInOrder(['Client requests', '2', 'open requests'], false);
    }

    public function test_inbox_is_admin_only(): void
    {
        $request = $this->request();

        $this->get(route('admin.client-requests.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.client-requests.dismiss', $request))->assertRedirect(route('admin.login'));
        $this->assertSame('open', $request->fresh()->status);
    }

    // Answering ----------------------------------------------------------------------

    public function test_ai_draft_prefills_the_knowledge_form(): void
    {
        $request = $this->request();
        $this->fakeGemini([
            'type' => 'delivery', 'keywords' => 'delivery, Samarkand',
            'question_uz' => 'Samarqandga yetkazasizmi?', 'question_ru' => 'Доставляете в Самарканд?', 'question_en' => 'Do you deliver to Samarkand?',
            'answer_uz' => 'Ha. [TO FILL: muddat]', 'answer_ru' => 'Да. [TO FILL: срок]', 'answer_en' => 'Yes. [TO FILL: delivery time]',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.knowledge.create', ['client_request' => $request->id, 'draft' => 1]))
            ->assertOk()
            ->assertSee('Answering customer questions')
            ->assertSee('Доставляете в Самарканд?')
            ->assertSee('Yes. [TO FILL: delivery time]')
            ->assertSee('name="client_request_id" value="'.$request->id.'"', false);

        Http::assertSent(fn (Request $r) => str_contains($r['system_instruction']['parts'][0]['text'], 'Доставляете в Самарканд?'));
    }

    public function test_entry_with_placeholders_cannot_be_saved(): void
    {
        $request = $this->request();

        $this->actingAs($this->admin())->post(route('admin.knowledge.store'), $this->entryForm($request, 'Yes. [TO FILL: delivery time]'))
            ->assertSessionHasErrors('answer_en');

        $this->assertSame(0, KnowledgeEntry::count());
    }

    public function test_saving_an_answer_resolves_the_group_and_ai_matches_other_requests(): void
    {
        $request = $this->request();
        $sameTopic = $this->request(['question' => 'Samarqandga yetkazib berasizmi?', 'locale' => 'uz']);
        $differentWording = $this->request(['question' => 'Can you ship to Samarkand region?', 'topic' => 'shipping to regions', 'locale' => 'en']);
        $unrelated = $this->request(['question' => 'Zebra ZT411 price?', 'topic' => 'zebra zt411 price']);
        $this->fakeGemini(['answered_ids' => [$differentWording->id, 99999]]);

        $this->actingAs($this->admin())->post(route('admin.knowledge.store'), $this->entryForm($request, 'Yes, in 2 days.'))
            ->assertRedirect(route('admin.client-requests.show', $request))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Answered 2 requests') && str_contains($m, 'matched 1 more'));

        $entry = KnowledgeEntry::sole();
        foreach ([$request, $sameTopic, $differentWording] as $r) {
            $this->assertSame('resolved', $r->fresh()->status);
            $this->assertSame($entry->id, $r->fresh()->knowledge_entry_id);
        }
        $this->assertSame('open', $unrelated->fresh()->status);

        // The AI was only shown the still-open requests.
        Http::assertSent(fn (Request $r) => str_contains($r['system_instruction']['parts'][0]['text'], "#{$unrelated->id}:")
            && ! str_contains($r['system_instruction']['parts'][0]['text'], "#{$request->id}:"));
    }

    public function test_link_existing_entry_and_dismiss(): void
    {
        $entry = $this->entry();
        $request = $this->request();
        $other = $this->request(['topic' => 'spam', 'question' => 'buy followers']);
        $this->fakeGemini(['answered_ids' => []]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.client-requests.link', $request), ['knowledge_entry_id' => $entry->id])
            ->assertRedirect(route('admin.client-requests.show', $request));
        $this->assertSame('resolved', $request->fresh()->status);

        $this->actingAs($admin)->post(route('admin.client-requests.link', $request), ['knowledge_entry_id' => 12345])
            ->assertSessionHasErrors('knowledge_entry_id');

        $this->actingAs($admin)->post(route('admin.client-requests.dismiss', $other))->assertRedirect(route('admin.client-requests.index'));
        $this->assertSame('dismissed', $other->fresh()->status);
    }

    public function test_answer_is_sent_once_to_each_telegram_customer_in_their_language(): void
    {
        $entry = $this->entry();
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'uz', 'step' => 'done', 'registered_at' => now()]);
        $conversation = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'uz', 'messages' => []]);
        $first = $this->request(['channel' => 'telegram', 'external_id' => '555', 'locale' => 'uz', 'status' => 'resolved', 'knowledge_entry_id' => $entry->id]);
        $this->request(['channel' => 'telegram', 'external_id' => '555', 'locale' => 'uz', 'status' => 'resolved', 'knowledge_entry_id' => $entry->id, 'question' => 'Yana soʻrayman']);
        $this->request(['channel' => 'telegram', 'external_id' => '777', 'locale' => 'en', 'status' => 'resolved', 'knowledge_entry_id' => $entry->id]);
        $web = $this->request(['status' => 'resolved', 'knowledge_entry_id' => $entry->id]);
        $this->fakeGemini([]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.client-requests.show', $first))
            ->assertOk()->assertSee('Send answer to 2 customers');

        $this->actingAs($admin)->post(route('admin.client-requests.notify', $first), ['knowledge_entry_id' => $entry->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'sent to 2 Telegram customers'));

        $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]);
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Ha, 2 kunda.', $sent->first(fn ($r) => (string) $r['chat_id'] === '555')['text']);
        $this->assertStringContainsString('Yes, in 2 days.', $sent->first(fn ($r) => (string) $r['chat_id'] === '777')['text']);

        $this->assertSame(0, ClientRequest::where('channel', 'telegram')->whereNull('notified_at')->count());
        $this->assertNull($web->fresh()->notified_at);
        $this->assertSame('admin', collect($conversation->fresh()->messages)->last()['role']);

        // Nothing left to send.
        $this->actingAs($admin)->post(route('admin.client-requests.notify', $first), ['knowledge_entry_id' => $entry->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'sent to 0'));
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')));
    }

    public function test_notify_does_nothing_while_another_send_is_in_progress(): void
    {
        $entry = $this->entry();
        $request = $this->request(['channel' => 'telegram', 'external_id' => '555', 'status' => 'resolved', 'knowledge_entry_id' => $entry->id]);
        $this->fakeGemini([]);
        $lock = Cache::lock("client-requests.notify.{$entry->id}", 120);
        $lock->get();

        $this->actingAs($this->admin())->post(route('admin.client-requests.notify', $request), ['knowledge_entry_id' => $entry->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'already being sent'));

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'));
        $this->assertNull($request->fresh()->notified_at);
        $lock->release();
    }

    // Reply suggestions --------------------------------------------------------------

    public function test_admin_gets_an_ai_reply_suggestion_for_a_telegram_conversation(): void
    {
        $conversation = ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'messages' => [
                ['role' => 'user', 'content' => 'Есть Zebra ZT411?'],
                ['role' => 'assistant', 'content' => 'Уточню у команды.', 'answered' => false],
                ['role' => 'admin', 'content' => 'Проверяю склад.', 'author' => 'Admin'],
            ],
        ]);
        $this->fakeGemini(['reply' => 'Да, есть. Цена: [TO FILL: price].']);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.chat-conversations.suggest', $conversation))
            ->assertOk()->assertJson(['reply' => 'Да, есть. Цена: [TO FILL: price].']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateContent')
            && $r['contents'][0]['parts'][0]['text'] === 'Есть Zebra ZT411?'
            && $r['contents'][2] === ['role' => 'model', 'parts' => [['text' => 'Проверяю склад.']]]);

        // A suggestion with an unfilled placeholder cannot be sent as is.
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $conversation), ['message' => 'Да, есть. Цена: [TO FILL: price].'])
            ->assertSessionHasErrors('message');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'));
    }

    public function test_suggestion_failures_and_web_conversations(): void
    {
        $telegram = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => 'Привет']]]);
        $web = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'web', 'locale' => 'ru', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('error', 500)]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.chat-conversations.suggest', $telegram))->assertStatus(422)->assertJsonStructure(['message']);
        $this->actingAs($admin)->postJson(route('admin.chat-conversations.suggest', $web))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $telegram))->assertOk()->assertSee('Suggest reply');
    }

    private function entryForm(ClientRequest $request, string $answerEn): array
    {
        return [
            'client_request_id' => $request->id, 'type' => 'delivery', 'is_active' => 1,
            'question_uz' => 'Samarqandga yetkazasizmi?', 'question_ru' => 'Доставляете в Самарканд?', 'question_en' => 'Do you deliver to Samarkand?',
            'answer_uz' => 'Ha, 2 kunda.', 'answer_ru' => 'Да, за 2 дня.', 'answer_en' => $answerEn,
        ];
    }
}
