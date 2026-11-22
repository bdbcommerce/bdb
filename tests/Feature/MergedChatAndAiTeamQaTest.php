<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA of commit 33d47ad: one conversation per Telegram customer (bot chat + Premium-account chat). */
class MergedChatAndAiTeamQaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.chat_id' => '-100555']);
        $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Staff One']);
        $n = 900;
        Http::fake(['api.telegram.org/*' => function () use (&$n) {
            return Http::response(['ok' => true, 'result' => ['message_id' => $n++]]);
        }]);
    }

    private function conv(string $channel, array $messages, array $extra = []): ChatConversation
    {
        return ChatConversation::create($extra + [
            'uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => '7001', 'locale' => 'uz',
            'page' => 'Telegram', 'messages' => $messages, 'message_count' => count($messages),
        ]);
    }

    private function sends(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())->values()->all();
    }

    // --- merged conversation ------------------------------------------------------------------

    public function test_reply_goes_to_the_premium_account_when_the_customer_wrote_there_last(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        $c = $this->conv('telegram', [
            ['role' => 'user', 'content' => 'Salom', 'message_id' => 5, 'at' => now()->subMinutes(10)->toIso8601String()],
            ['role' => 'user', 'content' => 'Narxi?', 'message_id' => 5, 'via' => 'assistant', 'connection' => 'bc1', 'at' => now()->subMinutes(5)->toIso8601String()],
        ]);

        $this->actingAs($this->admin)->get(route('admin.chat-conversations.show', $c))->assertOk()
            ->assertSee('The customer last wrote to the Premium account')->assertSee('via Premium account');

        $this->actingAs($this->admin)->post(route('admin.chat-conversations.reply', $c), ['message' => 'Hello <b>x</b>'])
            ->assertRedirect(route('admin.chat-conversations.show', $c));
        $sent = $this->sends();
        $this->assertCount(1, $sent);
        $this->assertSame('bc1', $sent[0]['business_connection_id'] ?? null);
        $last = collect($c->fresh()->messages)->last();
        $this->assertSame(['admin', 'assistant', 'bc1', 'Staff One'], [$last['role'], $last['via'], $last['connection'], $last['author'] ?? null]);
        $this->assertFalse($c->fresh()->awaiting_reply);
    }

    public function test_reply_is_refused_without_reply_permission_and_nothing_is_sent(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => false]);
        $c = $this->conv('telegram', [
            ['role' => 'user', 'content' => 'Narxi?', 'message_id' => 5, 'via' => 'assistant', 'connection' => 'bc1', 'at' => now()->toIso8601String()],
        ]);
        $this->actingAs($this->admin)->from(route('admin.chat-conversations.show', $c))
            ->post(route('admin.chat-conversations.reply', $c), ['message' => 'Hello'])
            ->assertRedirect(route('admin.chat-conversations.show', $c))->assertSessionHas('telegram_error')->assertSessionHasInput('message', 'Hello');
        $this->assertSame([], $this->sends());
        $this->assertCount(1, $c->fresh()->messages);
    }

    public function test_reply_goes_to_the_bot_chat_when_the_customer_wrote_there_last(): void
    {
        $c = $this->conv('telegram', [
            ['role' => 'user', 'content' => 'Narxi?', 'message_id' => 5, 'via' => 'assistant', 'connection' => 'bc1', 'at' => now()->subMinutes(10)->toIso8601String()],
            ['role' => 'user', 'content' => 'Salom', 'message_id' => 6, 'at' => now()->toIso8601String()],
        ]);
        $this->actingAs($this->admin)->get(route('admin.chat-conversations.show', $c))->assertOk()->assertSee('The customer sees it as a message from the bot.');
        $this->actingAs($this->admin)->post(route('admin.chat-conversations.reply', $c), ['message' => 'Hi'])->assertRedirect();
        $sent = $this->sends();
        $this->assertCount(1, $sent);
        $this->assertArrayNotHasKey('business_connection_id', $sent[0]);
        $this->assertSame('7001', (string) $sent[0]['chat_id']);
    }

    public function test_merged_chat_shows_once_in_lists_and_on_the_user_page(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'profile_name' => 'Ali', 'locale' => 'uz', 'step' => TelegramUser::STEP_GUEST]);
        $this->conv('telegram', [
            ['role' => 'user', 'content' => 'Bot savol', 'message_id' => 5, 'at' => now()->subMinutes(10)->toIso8601String()],
            ['role' => 'user', 'content' => 'Premium savol <script>x</script>', 'message_id' => 5, 'via' => 'assistant', 'connection' => 'bc1', 'at' => now()->toIso8601String()],
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.telegram-chats.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '/admin/chat-conversations/'), 'one row in Telegram → Chats');
        $this->actingAs($this->admin)->get(route('admin.awaiting-replies.index'))->assertOk()->assertSee('Ali');

        $user = TelegramUser::sole();
        $this->actingAs($this->admin)->get(route('admin.telegram-users.show', $user))->assertOk()
            ->assertSee('via Premium account')->assertDontSee('<script>x</script>', false);
    }

    /**
     * Transition after deploy: the customer had a bot session (lower id) and a Premium session (channel business,
     * higher id) open at the same time. New Premium messages now go into the bot session — the newest by
     * updated_at — but the lists, Waiting for reply and reply routing pick the session with the highest id.
     */
    public function test_interleaved_legacy_business_session_does_not_hide_the_waiting_customer(): void
    {
        $bot = $this->interleaved();
        $this->actingAs($this->admin)->get(route('admin.awaiting-replies.index'))->assertOk()
            ->assertDontSee('Nobody is waiting')->assertSee(route('admin.chat-conversations.show', $bot), false);
    }

    public function test_interleaved_legacy_business_session_does_not_hide_the_live_session_in_conversations(): void
    {
        $bot = $this->interleaved();
        $this->actingAs($this->admin)->get(route('admin.chat-conversations.index'))->assertOk()
            ->assertSee(route('admin.chat-conversations.show', $bot), false);
    }

    public function test_interleaved_legacy_business_session_does_not_misroute_the_staff_reply(): void
    {
        $bot = $this->interleaved();
        $this->actingAs($this->admin)->post(route('admin.chat-conversations.reply', $bot), ['message' => 'Salom'])->assertRedirect();
        $sent = $this->sends();
        $this->assertCount(1, $sent);
        $this->assertArrayNotHasKey('business_connection_id', $sent[0], 'reply was routed to the Premium account');
        $this->assertSame('admin', collect($bot->fresh()->messages)->last()['role'], 'reply stored outside the live session');
    }

    private function interleaved(): ChatConversation
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        TelegramUser::create(['telegram_id' => '7001', 'profile_name' => 'Ali', 'locale' => 'uz', 'step' => TelegramUser::STEP_GUEST]);
        $bot = $this->conv('telegram', [
            ['role' => 'user', 'content' => 'Bot savol', 'message_id' => 5, 'at' => now()->subHours(3)->toIso8601String()],
            ['role' => 'assistant', 'content' => 'Javob', 'at' => now()->subHours(3)->toIso8601String()],
        ]);
        $legacy = $this->conv('business', [
            ['role' => 'user', 'content' => 'Eski premium', 'message_id' => 40, 'at' => now()->subHours(2)->toIso8601String()],
            ['role' => 'owner', 'content' => 'Ha', 'message_id' => 41, 'at' => now()->subHours(2)->toIso8601String()],
        ], ['business_connection_id' => 'bc1', 'page' => 'Account: Aziz']);
        $this->assertGreaterThan($bot->id, $legacy->id);

        // After deploy: the customer writes in the bot chat again (lands in the merged session $bot).
        $bot->messages = [...$bot->messages, ['role' => 'user', 'content' => 'Yangi savol', 'message_id' => 6, 'at' => now()->toIso8601String()]];
        $bot->message_count = count($bot->messages);
        $bot->save();
        $this->assertTrue($bot->fresh()->awaiting_reply);

        return $bot;
    }
}
