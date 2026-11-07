<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: Telegram в†’ Chats menu, nav state, and "Waiting for reply" after the late_edit change. */
class TelegramChatsQaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => false,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => '777', 'name' => 'Sales 1']);
    }

    private function webhook(array $update): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++] + $update,
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function business(string $text, bool $fromOwner = false, int $chatId = 555, ?int $messageId = null, bool $edited = false, string $name = 'Aziz'): int
    {
        $messageId ??= 1000 + $this->updateId;
        $this->webhook([($edited ? 'edited_business_message' : 'business_message') => [
            'business_connection_id' => 'conn-1', 'message_id' => $messageId,
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => $name],
            'from' => ['id' => $fromOwner ? 777 : $chatId, 'first_name' => $fromOwner ? 'BDB' : $name],
            'date' => now()->timestamp, 'text' => $text,
        ]]);

        return $messageId;
    }

    private function bot(string $text, int $messageId, bool $edited = false): void
    {
        $this->webhook([($edited ? 'edited_message' : 'message') => [
            'message_id' => $messageId, 'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'language_code' => 'ru'], 'date' => now()->timestamp, 'text' => $text,
        ]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // --- Telegram в†’ Chats --------------------------------------------------------

    public function test_nav_highlights_only_one_item(): void
    {
        $c = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '1', 'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => 'Q']], 'message_count' => 1]);
        $admin = $this->admin();
        $active = fn (string $html) => preg_match_all('/<a[^>]*aria-current="page"[^>]*>/', $html, $m) ? $m[0] : [];

        $tg = $active($this->actingAs($admin)->get(route('admin.telegram-chats.index'))->getContent());
        $this->assertCount(1, $tg);
        $this->assertStringContainsString(route('admin.telegram-chats.index'), $tg[0]);

        $conv = $active($this->actingAs($admin)->get(route('admin.chat-conversations.index'))->getContent());
        $this->assertCount(1, $conv);
        $this->assertStringContainsString(route('admin.chat-conversations.index'), $conv[0]);

        $show = $active($this->actingAs($admin)->get(route('admin.chat-conversations.show', $c))->getContent());
        $this->assertCount(1, $show);
        $this->assertStringContainsString(route('admin.chat-conversations.index'), $show[0]);
    }

    public function test_filter_tabs_keep_the_telegram_route_and_channel_cannot_widen(): void
    {
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'business', 'external_id' => '2', 'locale' => 'ru', 'title' => 'BizTitle',
            'messages' => [['role' => 'user', 'content' => 'Biz Q']], 'message_count' => 1, 'unanswered_count' => 1]);
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '3', 'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => 'Bot Q']], 'message_count' => 1, 'unanswered_count' => 1]);
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('admin.telegram-chats.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.telegram-chats.index'), '#').'\?filter=unanswered[^"]*"#', $html);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.telegram-chats.index'), '#').'\?filter=leads[^"]*"#', $html);
        $this->assertStringNotContainsString(route('admin.chat-conversations.index', ['filter' => 'unanswered']), $html);

        foreach (['business', 'group', 'web', ['business'], ''] as $ch) {
            $this->actingAs($admin)->get(route('admin.telegram-chats.index', ['filter' => 'unanswered', 'channel' => $ch]))
                ->assertOk()->assertSee('Bot Q')->assertDontSee('BizTitle')->assertDontSee('Biz Q');
        }
    }

    public function test_telegram_chats_access_control(): void
    {
        $this->get(route('admin.telegram-chats.index'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.telegram-chats.index'))->assertRedirect(route('admin.login'));
    }

    // --- Waiting for reply after late_edit -----------------------------------------

    public function test_business_late_edit_after_mark_answered_stays_off(): void
    {
        $this->business('Question');
        $c = ChatConversation::sole();
        $this->actingAs($this->admin())->post(route('admin.chat-conversations.handled', $c))->assertRedirect();
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        $this->business('Unknown (edited)', messageId: 1, edited: true);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        // A real new message after that still brings it back.
        $this->business('New question');
        $this->assertSame(1, ChatConversation::awaitingReply()->count());
    }

    public function test_business_late_edit_while_waiting_keeps_waiting_since(): void
    {
        $this->business('Question');
        $since = ChatConversation::sole()->last_message_at;
        $this->travel(2)->hours();
        $this->business('Unknown (edited)', messageId: 1, edited: true);
        $c = ChatConversation::sole();
        $this->assertTrue($c->awaiting_reply);
        $this->assertEquals($since->timestamp, $c->last_message_at->timestamp);
    }

    public function test_business_late_edit_after_owner_reply_stays_off(): void
    {
        $this->business('Question');
        $this->business('Answer', fromOwner: true);
        $this->business('Unknown (edited)', messageId: 1, edited: true);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());
    }

    public function test_bot_late_edit_after_mark_answered_stays_off(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
        $this->bot('Question', 5);
        $this->actingAs($this->admin())->post(route('admin.chat-conversations.handled', ChatConversation::sole()))->assertRedirect();
        $this->bot('Edited unknown', 1, edited: true);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());
        $this->bot('Real new', 6);
        $this->assertSame(1, ChatConversation::awaitingReply()->count());
    }

    /** A late edit arriving >24h after the waiting message must not hide the waiting chat. */
    public function test_bot_late_edit_in_new_session_does_not_hide_waiting_chat(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
        $this->bot('Hello?', 5);
        $this->assertSame(1, ChatConversation::awaitingReply()->count());

        $this->travel(25)->hours();
        $this->bot('Very old message (edited)', 1, edited: true);

        $this->assertSame(1, ChatConversation::awaitingReply()->count(), 'customer is still waiting for "Hello?"; rows: '.ChatConversation::count());
    }

    public function test_business_late_edit_after_24h_does_not_hide_waiting_chat(): void
    {
        $this->business('Hello?');
        $this->travel(25)->hours();
        $this->business('Very old (edited)', messageId: 1, edited: true);
        $this->assertSame(1, ChatConversation::awaitingReply()->count());
    }

    public function test_sort_by_first_unanswered_message_with_repeated_hello(): void
    {
        $this->business('Hello?', chatId: 1, name: 'Alpha');      // t0
        $this->travel(1)->hours();
        $this->business('Hi', chatId: 2, name: 'Bravo');          // t0+1h
        $this->travel(1)->hours();
        $this->business('Hello?', chatId: 1, name: 'Alpha');      // t0+2h, same run
        $this->travel(1)->hours();
        $this->business('Hello?', chatId: 1, name: 'Alpha');      // t0+3h, same run
        $this->business('Bravo late edit', chatId: 2, messageId: 1, edited: true, name: 'Bravo');

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.awaiting-replies.index'))->assertOk()->assertSeeInOrder(['Alpha', 'Bravo']);
        $this->actingAs($admin)->get(route('admin.awaiting-replies.index', ['sort' => 'newest']))->assertOk()->assertSeeInOrder(['Bravo', 'Alpha']);

        // Owner answers Alpha, Alpha writes again: waiting since the new message, now newer than Bravo.
        $this->business('Answer', fromOwner: true, chatId: 1, name: 'Alpha');
        $this->travel(1)->hours();
        $this->business('Hello?', chatId: 1, name: 'Alpha');
        $this->actingAs($admin)->get(route('admin.awaiting-replies.index'))->assertOk()->assertSeeInOrder(['Bravo', 'Alpha']);
    }
}
