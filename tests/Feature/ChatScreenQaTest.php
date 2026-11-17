<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Messenger-style conversation screen (admin/chat-conversations/show + _messages partial):
 * every channel renders, all message kinds and markers are shown, user content is escaped,
 * odd timestamps don't break the page.
 */
class ChatScreenQaTest extends TestCase
{
    use RefreshDatabase;

    private const XSS = '<script>alert(1)</script>';

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function conversation(string $channel, array $messages, array $extra = []): ChatConversation
    {
        return ChatConversation::create($extra + [
            'uuid' => (string) Str::uuid(), 'channel' => $channel, 'locale' => 'uz',
            'message_count' => count($messages), 'messages' => $messages,
        ]);
    }

    public function test_web_chat_without_timestamps_renders_bubbles_and_unanswered_warning(): void
    {
        $lead = ChatLead::create(['name' => 'Aziz '.self::XSS, 'phone' => '+998901234567', 'locale' => 'uz']);
        $c = $this->conversation('web', [
            ['role' => 'user', 'content' => 'Salom '.self::XSS],
            ['role' => 'assistant', 'content' => 'Javob yo‘q', 'answered' => false],
            ['role' => 'user', 'content' => str_repeat('https://example.com/'.str_repeat('a', 300), 2)],
            ['role' => 'assistant', 'content' => 'Привет', 'answered' => true],
        ], ['page' => '/uz/contact', 'chat_lead_id' => $lead->id]);

        $html = $this->actingAs($this->admin())->get(route('admin.chat-conversations.show', $c))
            ->assertOk()
            ->assertSee('Website visitor')
            ->assertSee('No answer in the site data.')
            ->assertSee(route('admin.knowledge.create'), false)
            ->assertSee('Website chat — answered by the AI assistant', false)
            ->assertDontSee('id="reply-message"', false)
            ->assertSee('#lead-'.$lead->id, false)
            ->assertSee('Привет')
            ->assertDontSee(self::XSS, false)
            ->assertSee(e('Salom '.self::XSS), false)
            ->getContent();

        $this->assertStringNotContainsString('<time', $html, 'no timestamps for website chats');
        $this->assertStringContainsString('bg-[#d9fdd3]', $html);
        $this->assertStringContainsString('ring-amber-400', $html);
    }

    public function test_telegram_chat_shows_media_transcript_steps_admin_and_composer(): void
    {
        TelegramUser::create(['telegram_id' => '777', 'username' => 'dilnoza', 'locale' => 'uz', 'step' => 'done',
            'name' => 'Dilnoza Oʻrinova', 'phone' => '+998901112233', 'registered_at' => now()]);
        $c = $this->conversation('telegram', [
            ['role' => 'user', 'content' => 'Dilnoza', 'aside' => true, 'at' => now()->subDays(3)->toIso8601String()],
            ['role' => 'user', 'type' => 'photo', 'file_id' => 'PHOTO1', 'caption' => 'cap '.self::XSS, 'at' => 'not a date'],
            ['role' => 'user', 'type' => 'voice', 'file_id' => 'VOICE1', 'content' => 'transcript text', 'transcribed' => true, 'aside' => true, 'at' => now()->subDay()->toIso8601String()],
            ['role' => 'user', 'type' => 'document', 'at' => now()->toIso8601String()],
            ['role' => 'assistant', 'content' => 'Javob', 'answered' => true, 'at' => now()->toIso8601String()],
            ['role' => 'admin', 'author' => 'Admin '.self::XSS, 'content' => 'Staff reply', 'at' => now()->toIso8601String(), 'edited' => true],
            ['role' => 'user', 'content' => 'deleted one', 'deleted' => true, 'at' => 1700000000],
        ], ['external_id' => '777', 'page' => 'Telegram bot']);

        $html = $this->actingAs($this->admin())
            ->withSession(['telegram_error' => 'Telegram did not deliver '.self::XSS, '_old_input' => ['message' => 'old '.self::XSS]])
            ->get(route('admin.chat-conversations.show', $c))
            ->assertOk()
            ->assertSee('Dilnoza Oʻrinova')
            ->assertSee('Bot step — not sent to the AI')
            ->assertSee(route('admin.telegram.media', 'PHOTO1'), false)
            ->assertSee(route('admin.telegram.media', 'VOICE1'), false)
            ->assertSee('Load')
            ->assertSee('Open')
            ->assertSee('Transcript:')
            ->assertSee('Not answered yet')
            ->assertSee('not stored')
            ->assertSee('BDB team')
            ->assertSee('edited')
            ->assertSee('deleted in Telegram')
            ->assertSee('Today')
            ->assertSee('Yesterday')
            ->assertSee('id="reply-message"', false)
            ->assertSee('Suggest reply')
            ->assertSee('Message the customer in Telegram')
            ->assertSee(route('admin.chat-conversations.reply', $c), false)
            ->assertSee(route('admin.telegram-users.show', TelegramUser::first()), false)
            ->assertDontSee(self::XSS, false)
            ->getContent();

        $this->assertStringContainsString(e('Telegram did not deliver '.self::XSS), $html);
        $this->assertStringContainsString(e('old '.self::XSS), $html, 'old input kept in textarea');
    }

    public function test_business_and_group_chats_render_with_labels(): void
    {
        $b = $this->conversation('business', [
            ['role' => 'user', 'sender' => 'Client '.self::XSS, 'content' => 'Hi', 'at' => now()->toIso8601String()],
            ['role' => 'owner', 'sender' => 'Owner Name', 'content' => 'Hello', 'at' => now()->toIso8601String()],
            ['role' => 'bot', 'sender' => 'BDB bot', 'content' => 'Ismingiz?', 'at' => now()->toIso8601String()],
        ], ['external_id' => '555', 'title' => 'Client chat']);
        $g = $this->conversation('group', [
            ['role' => 'member', 'sender' => 'Ali', 'content' => 'one', 'at' => now()->toIso8601String()],
            ['role' => 'member', 'sender' => 'Vali', 'content' => 'two', 'at' => now()->toIso8601String()],
        ], ['external_id' => '-100', 'title' => 'Sales group']);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $b))->assertOk()
            ->assertSee('Account owner · Owner Name')
            ->assertSee('BDB bot')
            ->assertSee('Recorded only — the bot does not reply in this chat.')
            ->assertDontSee('id="reply-message"', false)
            ->assertDontSee(self::XSS, false);
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $g))->assertOk()
            ->assertSee('Sales group')->assertSee('Ali')->assertSee('Vali');
    }

    public function test_awaiting_reply_shows_mark_answered_and_delete(): void
    {
        $c = $this->conversation('telegram', [['role' => 'user', 'content' => 'Savol?', 'at' => now()->toIso8601String()]], ['external_id' => '888']);
        $this->assertTrue($c->fresh()->awaiting_reply);

        $this->actingAs($this->admin())->get(route('admin.chat-conversations.show', $c))->assertOk()
            ->assertSee('Mark as answered')
            ->assertSee(route('admin.chat-conversations.handled', $c), false)
            ->assertSee(route('admin.chat-conversations.destroy', $c), false)
            ->assertSee('Message to the customer');
    }

    public function test_empty_conversation_and_empty_title(): void
    {
        $c = $this->conversation('business', [], ['external_id' => '1', 'title' => '']);
        $this->actingAs($this->admin())->get(route('admin.chat-conversations.show', $c))->assertOk()
            ->assertSee('No messages');
    }

    public function test_telegram_user_page_renders_chat_box(): void
    {
        $u = TelegramUser::create(['telegram_id' => '999', 'locale' => 'ru', 'step' => 'done', 'name' => 'Иван', 'registered_at' => now()]);
        $this->conversation('telegram', [
            ['role' => 'user', 'content' => 'Вопрос '.self::XSS, 'at' => now()->toIso8601String()],
            ['role' => 'assistant', 'content' => 'Ответ', 'answered' => true],
        ], ['external_id' => '999']);

        $this->actingAs($this->admin())->get(route('admin.telegram-users.show', $u))->assertOk()
            ->assertSee('bg-[#efeae2]', false)
            ->assertSee('Open this conversation')
            ->assertSee('Ответ')
            ->assertDontSee(self::XSS, false);
    }
}
