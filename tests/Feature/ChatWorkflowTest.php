<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function geminiReply(string $reply, bool $answered = true, bool $offerCallback = false): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'reply' => $reply,
            'suggestions' => [],
            'offer_callback' => $offerCallback,
            'answered' => $answered,
        ])]]]]]];
    }

    private function fakeGemini(array ...$replies): void
    {
        config(['services.gemini.key' => 'test-key']);

        $sequence = Http::sequence();
        foreach ($replies as $reply) {
            $sequence->push($reply);
        }

        Http::fake(['generativelanguage.googleapis.com/*' => $sequence]);
    }

    private function ask(string $uuid, array $messages)
    {
        return $this->postJson('/chat/message', ['locale' => 'en', 'conversation' => $uuid, 'page' => 'Brands', 'messages' => $messages]);
    }

    public function test_every_exchange_is_stored_in_one_conversation(): void
    {
        $uuid = (string) Str::uuid();

        $this->fakeGemini(
            $this->geminiReply('We sell Zebra.'),
            $this->geminiReply('I don\'t have that information.', answered: false, offerCallback: true),
        );

        $this->ask($uuid, [['role' => 'user', 'content' => 'Which brands?']])
            ->assertOk()->assertJsonMissingPath('answered');

        $this->ask($uuid, [
            ['role' => 'user', 'content' => 'Which brands?'],
            ['role' => 'assistant', 'content' => 'We sell Zebra.'],
            ['role' => 'user', 'content' => 'Do you have it in stock?'],
        ])->assertOk()->assertJson(['offer_callback' => true]);

        $conversation = ChatConversation::sole();
        $this->assertSame($uuid, $conversation->uuid);
        $this->assertSame('Brands', $conversation->page);
        $this->assertSame(4, $conversation->message_count);
        $this->assertSame(1, $conversation->unanswered_count);
        $this->assertSame('Do you have it in stock?', $conversation->messages[2]['content']);
        $this->assertFalse($conversation->messages[3]['answered']);
    }

    public function test_gemini_timeout_returns_fallback_and_is_logged_as_unanswered(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);
        $uuid = (string) Str::uuid();

        $this->ask($uuid, [['role' => 'user', 'content' => 'Hello?']])
            ->assertOk()
            ->assertJson(['reply' => __('chat.error', [], 'en'), 'offer_callback' => true]);

        $this->assertSame(1, ChatConversation::where('uuid', $uuid)->value('unanswered_count'));
    }

    public function test_message_without_conversation_id_is_still_answered(): void
    {
        $this->fakeGemini($this->geminiReply('Hello!'));

        $this->postJson('/chat/message', ['locale' => 'en', 'messages' => [['role' => 'user', 'content' => 'Hi']]])
            ->assertOk()->assertJson(['reply' => 'Hello!']);

        $this->assertSame(0, ChatConversation::count());
    }

    public function test_lead_links_conversation_and_notifies_telegram(): void
    {
        config(['services.telegram.bot_token' => 'BOT', 'services.telegram.chat_id' => '-100123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $conversation = ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'ru', 'messages' => []]);

        $this->postJson('/chat/lead', [
            'locale' => 'ru',
            'conversation' => $conversation->uuid,
            'name' => 'Дилшод <b>',
            'phone' => '+998 90 123 45 67',
            'topic' => 'RFID для склада',
        ])->assertOk();

        $lead = ChatLead::sole();
        $this->assertSame($lead->id, $conversation->fresh()->chat_lead_id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org/botBOT/sendMessage')
            && $r['chat_id'] === '-100123'
            && $r['parse_mode'] === 'HTML'
            && str_contains($r['text'], 'Дилшод &lt;b&gt;')
            && str_contains($r['text'], 'RFID для склада')
            && str_contains($r['text'], '#lead-'.$lead->id));
    }

    public function test_lead_is_saved_without_telegram_config_or_when_telegram_is_down(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response('down', 502)]);

        $this->postJson('/chat/lead', ['locale' => 'en', 'name' => 'A', 'phone' => '+998901234567'])->assertOk();
        Http::assertNothingSent();

        config(['services.telegram.bot_token' => 'BOT', 'services.telegram.chat_id' => '1']);
        $this->postJson('/chat/lead', ['locale' => 'en', 'name' => 'B', 'phone' => '+998901234568'])->assertOk();

        $this->assertSame(2, ChatLead::count());
    }

    public function test_invalid_phone_gets_a_localized_message(): void
    {
        $this->postJson('/chat/lead', ['locale' => 'ru', 'name' => 'A', 'phone' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', __('chat.phone_invalid', [], 'ru'));
    }

    public function test_editing_site_content_refreshes_assistant_knowledge(): void
    {
        Cache::put('chat.knowledge.en', 'stale', 600);

        Brand::create(['name' => ['en' => 'Zebra']]);

        $this->assertNull(Cache::get('chat.knowledge.en'));
    }

    public function test_old_conversations_are_pruned(): void
    {
        $old = ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'en', 'messages' => []]);
        $old->forceFill(['updated_at' => now()->subDays(ChatConversation::KEEP_DAYS + 1)])->saveQuietly();
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'en', 'messages' => []]);

        $this->artisan('model:prune', ['--model' => [ChatConversation::class]])->assertSuccessful();

        $this->assertSame(1, ChatConversation::count());
        $this->assertModelMissing($old);
    }

    public function test_admin_can_browse_and_filter_conversations(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $answered = ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'en', 'messages' => [
            ['role' => 'user', 'content' => 'Which brands do you sell?'],
            ['role' => 'assistant', 'content' => 'Zebra.', 'answered' => true],
        ], 'message_count' => 2]);
        $gap = ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'messages' => [
            ['role' => 'user', 'content' => 'Do you rent printers?'],
            ['role' => 'assistant', 'content' => 'I do not know.', 'answered' => false],
        ], 'message_count' => 2, 'unanswered_count' => 1]);

        $this->actingAs($admin)->get(route('admin.chat-conversations.index'))
            ->assertOk()->assertSee('Which brands do you sell?')->assertSee('Do you rent printers?');

        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['filter' => 'unanswered']))
            ->assertOk()->assertDontSee('Which brands do you sell?')->assertSee('Do you rent printers?');

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $gap))
            ->assertOk()->assertSee('No answer in the site data.')->assertSee(route('admin.knowledge.create'), false);

        $this->actingAs($admin)->delete(route('admin.chat-conversations.destroy', $answered))
            ->assertRedirect(route('admin.chat-conversations.index'));
        $this->assertModelMissing($answered);
    }

    public function test_conversations_are_admin_only(): void
    {
        $this->get(route('admin.chat-conversations.index'))->assertRedirect();
    }
}
