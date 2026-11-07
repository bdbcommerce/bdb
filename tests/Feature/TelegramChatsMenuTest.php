<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Telegram → Chats: only the bot's chats (customer + assistant). Conversations: every source. */
class TelegramChatsMenuTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(string $channel, array $messages, array $extra = []): ChatConversation
    {
        return ChatConversation::create($extra + [
            'uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => (string) random_int(1, 99999),
            'locale' => 'ru', 'messages' => $messages, 'message_count' => count($messages),
        ]);
    }

    public function test_telegram_chats_show_only_bot_chats_and_conversations_show_every_source(): void
    {
        $bot = $this->conversation('telegram', [
            ['role' => 'user', 'content' => 'Bot question'],
            ['role' => 'assistant', 'content' => 'Assistant answer', 'answered' => true],
        ]);
        $this->conversation('business', [['role' => 'user', 'sender' => 'Aziz', 'content' => 'Business question']], ['title' => 'Aziz chat']);
        $this->conversation('group', [['role' => 'member', 'sender' => 'B', 'content' => 'Group question']], ['title' => 'Dealers']);
        $this->conversation('web', [['role' => 'user', 'content' => 'Website question']]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.telegram-chats.index'))
            ->assertOk()
            ->assertSee('Telegram bot chats')
            ->assertSee('Bot question')
            ->assertDontSee('Business question')->assertDontSee('Dealers')->assertDontSee('Website question')
            ->assertDontSee('All types');

        // A channel parameter cannot widen the bot list.
        $this->actingAs($admin)->get(route('admin.telegram-chats.index', ['channel' => 'web']))
            ->assertOk()->assertSee('Bot question')->assertDontSee('Website question');

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $bot))->assertOk()->assertSee('Assistant answer');

        $this->actingAs($admin)->get(route('admin.chat-conversations.index'))
            ->assertOk()->assertSee('All types')
            ->assertSee('Bot question')->assertSee('Aziz chat')->assertSee('Dealers')->assertSee('Website question')
            ->assertSee('Website')->assertSee('Telegram bot')->assertSee('Telegram Business')->assertSee('Telegram groups');
    }
}
