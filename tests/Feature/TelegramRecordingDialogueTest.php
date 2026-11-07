<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ClientRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: recording every bot message must not leak registration/aside messages into the AI dialogue. */
class TelegramRecordingDialogueTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    private int $messageId = 100;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-1001234567890',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'reply' => 'We sell Zebra scanners.',
                    'suggestions' => ['What printers do you have?'],
                    'offer_callback' => true,
                    'answered' => true,
                    'topic' => 'scanners',
                    'category' => 'equipment',
                ])]]]]],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function send(array $message, string $key = 'message', ?int $messageId = null): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, $key => [
            'message_id' => $messageId ?? $this->messageId++,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'username' => 'aziz_uz'],
            'date' => time(),
        ] + $message], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function register(): void
    {
        $this->send(['text' => '/start']);
        $this->send(['text' => '🇬🇧 English']);
        $this->send(['text' => 'Aziz']);
        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);
    }

    /** @return array<int, string> raw bodies of the Gemini requests */
    private function geminiBodies(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'generateContent'))
            ->map(fn ($pair) => json_encode($pair[0]['contents'], JSON_UNESCAPED_UNICODE))->values()->all();
    }

    public function test_full_flow_ai_history_excludes_registration_and_aside_messages(): void
    {
        $this->register();
        $this->send(['photo' => [['file_id' => 'p_small'], ['file_id' => 'p_big']], 'caption' => 'my label']);
        $this->send(['text' => '/language']);
        $this->send(['text' => '🇬🇧 English']);
        $this->send(['text' => 'Do you sell scanners?']);
        $this->send(['text' => 'And printers?']);

        $bodies = $this->geminiBodies();
        $this->assertCount(2, $bodies);
        foreach (['/start', 'Aziz', '998901234567', 'English', '/language', 'my label'] as $leak) {
            $this->assertStringNotContainsString($leak, $bodies[1], "AI history contains '$leak'");
        }
        $this->assertStringContainsString('Do you sell scanners?', $bodies[1]);
        $this->assertStringContainsString('And printers?', $bodies[1]);

        $conversation = ChatConversation::sole();
        $this->assertSame(
            ['Do you sell scanners?', 'We sell Zebra scanners.', 'And printers?', 'We sell Zebra scanners.'],
            array_column($conversation->textMessages(), 'content'),
        );
        // every incoming message is in the record: 4 registration + photo + 2 language + 2 questions, plus 2 replies
        $this->assertSame(11, $conversation->message_count);
        $this->assertSame(2, ClientRequest::count());
    }

    public function test_lead_transcript_contains_only_the_dialogue(): void
    {
        $this->register();
        $this->send(['text' => 'Do you sell scanners?']);
        $this->send(['text' => __('chat.tg_request_call', [], 'en')]);

        $lead = ChatLead::sole();
        $this->assertSame('telegram', $lead->channel);
        $this->assertSame('Do you sell scanners?', $lead->topic);
        $this->assertSame(['Do you sell scanners?', 'We sell Zebra scanners.'], array_column($lead->transcript, 'content'));
        // The button tap itself is recorded as aside
        $last = collect(ChatConversation::sole()->messages)->last();
        $this->assertTrue($last['aside']);
    }

    public function test_message_without_message_id_is_not_stored_twice(): void
    {
        $this->register();
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555],
            'date' => time(),
            'text' => 'No id question',
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $count = collect(ChatConversation::sole()->messages)->where('content', 'No id question')->count();
        $this->assertSame(1, $count, 'question without message_id is stored twice');
    }

    public function test_suggestion_tap_is_stored_as_the_question_the_ai_saw(): void
    {
        $this->register();
        $this->send(['text' => '💡 What printers do you have?']);

        $question = ChatConversation::sole()->textMessages()[0]['content'];
        $this->assertSame('What printers do you have?', $question, 'stored question keeps the button emoji');
    }

    public function test_edit_of_an_answered_question_keeps_it_in_the_dialogue(): void
    {
        $this->register();
        $this->send(['text' => 'Do you sell scaners?'], messageId: 500);
        $this->send(['text' => 'Do you sell scanners?'], 'edited_message', 500);

        $conversation = ChatConversation::sole();
        $this->assertSame('Do you sell scanners?', $conversation->textMessages()[0]['content']);
        $this->assertCount(2, $conversation->textMessages());
        $this->assertCount(1, $this->geminiBodies()); // edits are not answered
    }

    public function test_admin_views_render_aside_media_caption_and_unknown_types(): void
    {
        $this->register();
        $this->send(['photo' => [['file_id' => 'p_big']], 'caption' => 'Ёрлиқ <b>caption</b>']);
        $this->send(['checklist' => ['title' => 'x']]);
        $this->send(['new_chat_title' => 'x']);
        $this->send(['text' => 'Do you sell scanners?']);

        $admin = User::factory()->create(['is_admin' => true]);
        $conversation = ChatConversation::sole();

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $conversation))->assertOk()
            ->assertSee('/start')
            ->assertSee('Ёрлиқ &lt;b&gt;caption&lt;/b&gt;', false)
            ->assertSee('Checklist')
            ->assertDontSee('BOT/', false);

        $this->actingAs($admin)->get(route('admin.chat-conversations.index'))->assertOk()
            ->assertSee('1 question')
            ->assertSee('3 files'); // photo, checklist, new_chat_title (registration contact excluded)
    }

    public function test_index_preview_shows_the_first_question_not_start(): void
    {
        $this->register();
        $this->send(['text' => 'Do you sell scanners?']);

        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.chat-conversations.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Do you sell scanners?', $html, 'index preview shows "/start" instead of the first question');
    }

    public function test_web_chat_is_unchanged(): void
    {
        $this->postJson('/chat/message', ['messages' => [['role' => 'user', 'content' => 'Do you sell scanners?']], 'locale' => 'en', 'conversation' => (string) Str::uuid()])
            ->assertOk();

        $conversation = ChatConversation::sole();
        $this->assertSame('web', $conversation->channel);
        $this->assertSame([
            ['role' => 'user', 'content' => 'Do you sell scanners?'],
            ['role' => 'assistant', 'content' => 'We sell Zebra scanners.', 'answered' => true],
        ], $conversation->messages);
    }
}
