<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ChatConversationController;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Conversations list = one row per chat with a pager; a chat shows its last 7 days across sessions; IDs in the details. */
class ChatScreenHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function chat(string $channel, ?string $chatId, array $messages, string $updated = 'now'): ChatConversation
    {
        $c = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => $chatId,
            'locale' => 'uz', 'messages' => $messages, 'message_count' => count($messages)]);
        $c->timestamps = false;
        $c->forceFill(['updated_at' => now()->modify($updated), 'last_message_at' => now()->modify($updated)])->save();

        return $c;
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_list_shows_one_row_per_chat(): void
    {
        $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Old session question', 'at' => now()->subDays(3)->toIso8601String()]], '-3 days');
        $newest = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'New session question', 'at' => now()->toIso8601String()]]);
        $this->chat('web', null, [['role' => 'user', 'content' => 'Web one']]);
        $this->chat('web', null, [['role' => 'user', 'content' => 'Web two']]);

        $html = $this->actingAs($this->admin())->get(route('admin.chat-conversations.index'))->assertOk()
            ->assertSee('New session question')->assertDontSee('Old session question')
            ->assertSee('Web one')->assertSee('Web two')->assertSee('of 3 chats')->getContent();
        $this->assertStringContainsString(route('admin.chat-conversations.show', $newest), $html);
    }

    public function test_unanswered_filter_picks_the_newest_matching_session(): void
    {
        $old = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Unanswered one']], '-2 days');
        $old->forceFill(['unanswered_count' => 1])->save();
        $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Answered later']]);

        $this->actingAs($this->admin())->get(route('admin.chat-conversations.index', ['filter' => 'unanswered']))
            ->assertOk()->assertSee('Unanswered one')->assertDontSee('Answered later');
    }

    public function test_list_paginates(): void
    {
        foreach (range(1, ChatConversationController::PER_PAGE + 2) as $i) {
            $this->chat('web', null, [['role' => 'user', 'content' => "Question $i"]]);
        }
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.chat-conversations.index'))->assertOk()
            ->assertSee('1–'.ChatConversationController::PER_PAGE.' of '.(ChatConversationController::PER_PAGE + 2).' chats')
            ->assertSee('?page=2', false);
        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['page' => 2]))->assertOk()
            ->assertSee((ChatConversationController::PER_PAGE + 1).'–'.(ChatConversationController::PER_PAGE + 2).' of');
    }

    public function test_chat_shows_last_seven_days_across_sessions_and_loads_earlier(): void
    {
        $ancient = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Ten days ago', 'at' => now()->subDays(10)->toIso8601String()]], '-10 days');
        $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Three days ago', 'at' => now()->subDays(3)->toIso8601String()]], '-3 days');
        $current = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Today message', 'at' => now()->toIso8601String()]]);
        $this->chat('telegram', '999', [['role' => 'user', 'content' => 'Other customer', 'at' => now()->toIso8601String()]]);
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('admin.chat-conversations.show', $current))->assertOk()
            ->assertSeeInOrder(['Three days ago', 'Today message'])
            ->assertDontSee('Ten days ago')->assertDontSee('Other customer')
            ->assertSee('Show earlier messages')->getContent();
        $this->assertStringContainsString(e(route('admin.chat-conversations.show', [$current, 'days' => 14])), $html);

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', [$current, 'days' => 14]))->assertOk()
            ->assertSeeInOrder(['Ten days ago', 'Three days ago', 'Today message'])->assertDontSee('Show earlier messages');

        // An old session opened directly still shows all of its own messages.
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $ancient))->assertOk()->assertSee('Ten days ago');

        foreach (['abc', '-5', '99999'] as $days) {
            $this->actingAs($admin)->get(route('admin.chat-conversations.show', [$current, 'days' => $days]))->assertOk();
        }
    }

    public function test_web_chat_is_one_session(): void
    {
        $this->chat('web', null, [['role' => 'user', 'content' => 'Another visitor']]);
        $web = $this->chat('web', null, [['role' => 'user', 'content' => 'This visitor']]);

        $this->actingAs($this->admin())->get(route('admin.chat-conversations.show', $web))->assertOk()
            ->assertSee('This visitor')->assertDontSee('Another visitor')->assertDontSee('data-chat-id', false);
    }

    public function test_details_show_chat_id_and_from_ids(): void
    {
        $bot = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Hi']]);
        $group = $this->chat('group', '-100777', [
            ['role' => 'member', 'sender' => 'Bek', 'sender_id' => '4242', 'content' => 'A'],
            ['role' => 'member', 'sender' => 'Ali', 'sender_id' => '4343', 'content' => 'B'],
            ['role' => 'member', 'sender' => 'Bek', 'sender_id' => '4242', 'content' => 'C'],
        ]);
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('admin.chat-conversations.show', $bot))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-chat-id>.*?Chat ID.*?555/s', $html);
        $this->assertMatchesRegularExpression('/data-from-ids>.*?From ID<.*?555/s', $html);

        $html = $this->actingAs($admin)->get(route('admin.chat-conversations.show', $group))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-chat-id>.*?-100777/s', $html);
        $this->assertMatchesRegularExpression('/data-from-ids>.*?From IDs<.*?4242.*?Bek.*?4343.*?Ali/s', $html);
        $this->assertSame(['4242' => 'Bek', '4343' => 'Ali'], $group->fromIds());
    }
}
