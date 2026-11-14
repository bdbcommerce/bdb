<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin → Conversations list shows the Telegram chat ID and the sender's ID. */
class ConversationListIdsTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(string $channel, ?string $externalId, array $messages): void
    {
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => $externalId,
            'locale' => 'ru', 'messages' => $messages, 'message_count' => count($messages)]);
    }

    public function test_ids_are_shown_for_telegram_chats_only(): void
    {
        $this->conversation('telegram', '555', [['role' => 'user', 'content' => 'Salom']]);
        $this->conversation('group', '-1009988', [['role' => 'member', 'sender' => 'Bek', 'sender_id' => '4242', 'content' => 'Hi']]);
        $this->conversation('business', '777', [['role' => 'owner', 'sender' => 'BDB', 'content' => 'Yes']]);
        $this->conversation('web', null, [['role' => 'user', 'content' => 'Web question']]);

        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.chat-conversations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Chat ID 555 · From ID 555', $html);
        $this->assertStringContainsString('Chat ID -1009988 · From ID 4242', $html);
        $this->assertMatchesRegularExpression('/Chat ID 777\s*<\/p>/', $html, 'the owner wrote last: no customer ID claimed');
        $this->assertSame(3, substr_count($html, 'data-ids'));
    }
}
