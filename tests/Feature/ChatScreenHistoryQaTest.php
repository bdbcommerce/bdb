<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: one-row-per-chat list and multi-session chat history edge cases. */
class ChatScreenHistoryQaTest extends TestCase
{
    use RefreshDatabase;

    private function chat(string $channel, ?string $chatId, ?array $messages, string $updated = 'now', array $extra = []): ChatConversation
    {
        $c = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => $chatId,
            'locale' => 'uz', 'messages' => $messages, 'message_count' => count($messages ?? [])] + $extra);
        $c->timestamps = false;
        $c->forceFill(['updated_at' => now()->modify($updated), 'last_message_at' => now()->modify($updated)])->save();

        return $c;
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_access_control(): void
    {
        $c = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Hi']]);
        $this->get(route('admin.chat-conversations.index'))->assertRedirect();
        $this->get(route('admin.chat-conversations.show', $c))->assertRedirect();
        $user = User::factory()->create(['is_admin' => false]);
        $this->assertNotEquals(200, $this->actingAs($user)->get(route('admin.chat-conversations.index'))->status());
        $this->assertNotEquals(200, $this->actingAs($user)->get(route('admin.chat-conversations.show', $c))->status());
    }

    public function test_null_external_id_rows_are_each_listed(): void
    {
        $this->chat('telegram', null, [['role' => 'user', 'content' => 'Null A']]);
        $this->chat('telegram', null, [['role' => 'user', 'content' => 'Null B']]);
        $this->actingAs($this->admin())->get(route('admin.chat-conversations.index'))->assertOk()
            ->assertSee('Null A')->assertSee('Null B')->assertSee('of 2 chats');
    }

    public function test_business_connections_are_separate_chats_and_null_equals_empty(): void
    {
        $this->chat('business', '777', [['role' => 'user', 'content' => 'Conn A old']], '-1 day', ['business_connection_id' => 'A']);
        $this->chat('business', '777', [['role' => 'user', 'content' => 'Conn A new']], 'now', ['business_connection_id' => 'A']);
        $this->chat('business', '777', [['role' => 'user', 'content' => 'Conn B']], 'now', ['business_connection_id' => 'B']);
        $this->chat('group', '-1001', [['role' => 'member', 'content' => 'Grp old']], '-1 day', ['business_connection_id' => null]);
        $this->chat('group', '-1001', [['role' => 'member', 'content' => 'Grp new']], 'now', ['business_connection_id' => '']);
        // Older Premium-account sessions of one customer and the bot chat are one chat now: the newest is listed.
        $this->chat('telegram', '777', [['role' => 'user', 'content' => 'Bot 777']]);

        $this->actingAs($this->admin())->get(route('admin.chat-conversations.index'))->assertOk()
            ->assertDontSee('Conn A new')->assertDontSee('Conn A old')->assertDontSee('Conn B')
            ->assertSee('Grp new')->assertDontSee('Grp old')->assertSee('Bot 777')->assertSee('of 2 chats');
    }

    public function test_pagination_keeps_filter_and_channel(): void
    {
        foreach (range(1, 27) as $i) {
            $this->chat('telegram', (string) (1000 + $i), [['role' => 'user', 'content' => "Q$i"]], 'now', ['unanswered_count' => 1]);
        }
        $this->chat('web', null, [['role' => 'user', 'content' => 'Web unanswered']], 'now', ['unanswered_count' => 1]);
        $html = $this->actingAs($this->admin())
            ->get(route('admin.chat-conversations.index', ['channel' => 'telegram', 'filter' => 'unanswered']))
            ->assertOk()->assertSee('1–25 of 27 chats')->getContent();
        $this->assertMatchesRegularExpression('/href="[^"]*(channel=telegram[^"]*filter=unanswered|filter=unanswered[^"]*channel=telegram)[^"]*page=2/', $html);

        $this->actingAs($this->admin())->get(route('admin.telegram-chats.index', ['page' => 2]))->assertOk()
            ->assertSee('26–27 of 27 chats')->assertDontSee('Web unanswered');
    }

    public function test_invalid_query_values_do_not_break_the_list(): void
    {
        $this->chat('web', null, [['role' => 'user', 'content' => 'Hello']]);
        $admin = $this->admin();
        foreach ([['filter' => ['x']], ['channel' => ['x']], ['channel' => 'nope'], ['page' => 'abc'], ['page' => -3], ['page' => 999]] as $q) {
            $this->actingAs($admin)->get(route('admin.chat-conversations.index', $q))->assertOk();
        }
    }

    public function test_names_and_previews_are_escaped(): void
    {
        $xss = '<script>alert(1)</script>';
        TelegramUser::create(['telegram_id' => '555', 'name' => $xss, 'profile_name' => $xss]);
        $c = $this->chat('telegram', '555', [['role' => 'user', 'content' => $xss.' preview'], ['role' => 'admin', 'content' => 'x', 'author' => $xss]]);
        $g = $this->chat('group', '-100', [['role' => 'member', 'sender' => $xss, 'sender_id' => '<b>1</b>', 'content' => $xss]], 'now', ['title' => $xss]);
        $admin = $this->admin();
        foreach ([route('admin.chat-conversations.index'), route('admin.chat-conversations.show', $c), route('admin.chat-conversations.show', $g)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $url);
            $this->assertStringNotContainsString('<b>1</b>', $html, $url);
        }
    }

    public function test_history_tolerates_bad_data(): void
    {
        $this->chat('telegram', '555', [], '-3 days');
        $this->chat('telegram', '555', [], '-2 days');
        $this->chat('telegram', '555', [
            ['role' => 'user', 'content' => 'Bad at string', 'at' => 'not a date'],
            ['role' => 'user', 'content' => 'Bad at array', 'at' => ['x']],
            ['role' => 'user', 'content' => 'Bad at int', 'at' => 12345],
            ['role' => 'user', 'content' => 'No at'],
            ['content' => 'No role'],
        ], '-1 day');
        $current = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Current']]);
        $current->timestamps = false;
        $current->forceFill(['last_message_at' => null])->save();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $current))->assertOk()
            ->assertSee('Bad at string')->assertSee('No at')->assertSee('Current');
        $this->actingAs($admin)->get(route('admin.chat-conversations.index'))->assertOk()->assertSee('Current');

        foreach (['', 'abc', '-5', '0', '7.9', '1e10', '99999999999999999999', '13'] as $d) {
            $this->actingAs($admin)->get(route('admin.chat-conversations.show', [$current, 'days' => $d]))->assertOk();
        }
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $current).'?days[]=1')->assertOk();
    }

    public function test_show_earlier_link_at_the_max_window(): void
    {
        $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Very old', 'at' => now()->subDays(400)->toIso8601String()]], '-400 days');
        $c = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Now', 'at' => now()->toIso8601String()]]);
        $html = $this->actingAs($this->admin())->get(route('admin.chat-conversations.show', [$c, 'days' => 364]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Show earlier messages', $html, 'At the 364-day cap "Show earlier" only reloads the same page');
    }

    public function test_list_row_does_not_show_stale_waiting_dot_of_an_older_session(): void
    {
        $old = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Call me']], '-2 days');
        $old->timestamps = false;
        $old->forceFill(['chat_lead_id' => null])->saveQuietly();
        ChatConversation::whereKey($old->id)->update(['awaiting_reply' => true, 'chat_lead_id' => null]);
        $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Q'], ['role' => 'assistant', 'content' => 'A']]);
        $html = $this->actingAs($this->admin())->get(route('admin.chat-conversations.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<span class="sr-only">Waiting for reply</span>', $html);
    }

    public function test_leads_filter_row_of_older_session_has_no_waiting_dot(): void
    {
        $lead = ChatLead::create(['name' => 'A', 'phone' => '+998901112233', 'channel' => 'telegram', 'locale' => 'uz']);
        $old = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Call me']], '-2 days');
        ChatConversation::whereKey($old->id)->update(['awaiting_reply' => true, 'chat_lead_id' => $lead->id]);
        $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Q'], ['role' => 'assistant', 'content' => 'A']]);
        $html = $this->actingAs($this->admin())->get(route('admin.chat-conversations.index', ['filter' => 'leads']))
            ->assertOk()->assertSee('Call me')->getContent();
        $this->assertStringNotContainsString('<span class="sr-only">Waiting for reply</span>', $html,
            'Older session shown under Leads carries its stale awaiting_reply; scopeAwaitingReply would not count it');
    }

    public function test_reply_from_older_session_page_goes_to_the_newest_session(): void
    {
        config(['services.telegram.bot_token' => '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $old = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Old q', 'at' => now()->subDays(2)->toIso8601String()]], '-2 days');
        $new = $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Newest q', 'at' => now()->toIso8601String()]]);
        $admin = $this->admin();
        // The old session page now shows the newest message too, and offers the composer.
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $old))->assertOk()->assertSee('Newest q');
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $old), ['message' => 'Staff answer'])->assertRedirect();
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $new))->assertOk()
            ->assertSeeInOrder(['Newest q', 'Staff answer'], 'Reply sent from the older session page is shown above the newer customer message');
    }

    public function test_related_pages_still_render(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'name' => 'Ali']);
        $this->chat('telegram', '555', [['role' => 'user', 'content' => 'Old']], '-3 days');
        $this->chat('telegram', '555', [['role' => 'user', 'content' => 'New']]);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.awaiting-replies.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.telegram-users.index'))->assertOk()->assertSee('Ali');
        $u = TelegramUser::where('telegram_id', '555')->first();
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $u))->assertOk()->assertSee('Old')->assertSee('New');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }
}
