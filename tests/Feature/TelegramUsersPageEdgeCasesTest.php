<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Edge cases for Admin → Telegram → Users: search escaping, unicode, pagination, nav state, XSS, N+1.
 */
class TelegramUsersPageEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function tgUser(string $id, array $attributes = []): TelegramUser
    {
        return TelegramUser::create($attributes + [
            'telegram_id' => $id, 'locale' => 'uz', 'step' => TelegramUser::STEP_DONE, 'registered_at' => now(),
        ]);
    }

    private function conversation(string $externalId, string $channel, array $messages): ChatConversation
    {
        return ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => $externalId, 'locale' => 'uz',
            'messages' => $messages, 'message_count' => count($messages),
        ]);
    }

    public function test_search_finds_username_with_underscore(): void
    {
        $this->tgUser('1', ['name' => 'Aziz', 'username' => 'aziz_uz']);
        $this->tgUser('2', ['name' => 'Other', 'username' => 'azizXuz']);

        $this->actingAs($this->admin())->get(route('admin.telegram-users.index', ['q' => 'aziz_uz']))
            ->assertOk()->assertSee('Aziz')->assertDontSee('Other');
    }

    public function test_search_finds_literal_percent(): void
    {
        $this->tgUser('1', ['name' => 'Sale 100% Ltd']);

        $this->actingAs($this->admin())->get(route('admin.telegram-users.index', ['q' => '100%']))
            ->assertOk()->assertSee('Sale 100% Ltd')->assertDontSee('No users match');
    }

    public function test_percent_alone_is_not_a_wildcard(): void
    {
        $this->tgUser('1', ['name' => 'Plain name']);

        $this->actingAs($this->admin())->get(route('admin.telegram-users.index', ['q' => '%']))
            ->assertOk()->assertDontSee('Plain name');
    }

    public function test_search_cyrillic_and_uzbek_latin_exact_case(): void
    {
        $this->tgUser('1', ['name' => 'Азиз Каримов']);
        $this->tgUser('2', ['name' => 'Oʻgʻiloy Shoʻxrux']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => 'Каримов']))
            ->assertOk()->assertSee('Азиз Каримов')->assertDontSee('Shoʻxrux');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => 'Oʻgʻiloy']))
            ->assertOk()->assertSee('Shoʻxrux')->assertDontSee('Каримов');
    }

    public function test_search_by_phone_digits_and_telegram_id(): void
    {
        $this->tgUser('987654321', ['name' => 'Phone Person', 'phone' => '+998901234567']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => '+998 90 123-45-67']))
            ->assertOk()->assertSee('Phone Person');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => '87654']))
            ->assertOk()->assertSee('Phone Person');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => str_repeat('я', 500)]))
            ->assertOk()->assertSee('No users match');
    }

    public function test_pagination_keeps_query_string_and_ordering_by_last_message(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            $this->tgUser((string) (1000 + $i), ['name' => 'Bulk '.$i]);
        }
        $admin = $this->admin();

        $page1 = $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => 'Bulk', 'filter' => 'registered']));
        $page1->assertOk();
        $html = $page1->getContent();
        $this->assertMatchesRegularExpression('/href="[^"]*page=2[^"]*"/', $html);
        preg_match('/href="([^"]*page=2[^"]*)"/', $html, $m);
        $next = html_entity_decode($m[1]);
        $this->assertStringContainsString('q=Bulk', $next);
        $this->assertStringContainsString('filter=registered', $next);

        $this->actingAs($admin)->get($next)->assertOk()->assertSee('Bulk');

        // A user with a recent message is listed first.
        $this->conversation('1001', 'telegram', [['role' => 'user', 'content' => 'x']]);
        $content = $this->actingAs($admin)->get(route('admin.telegram-users.index'))->getContent();
        $this->assertLessThan(strpos($content, 'Bulk 35'), strpos($content, 'Bulk 1<'));
    }

    public function test_index_has_no_n_plus_one(): void
    {
        $admin = $this->admin();
        $count = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get(route('admin.telegram-users.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->tgUser('1', ['name' => 'A']);
        $this->conversation('1', 'telegram', [['role' => 'user', 'content' => 'x']]);
        $few = $count();
        for ($i = 2; $i <= 25; $i++) {
            $this->tgUser((string) $i, ['name' => 'U'.$i]);
            $this->conversation((string) $i, 'telegram', [['role' => 'user', 'content' => 'x']]);
        }
        $many = $count();

        $this->assertSame($few, $many, "Index queries grew from $few to $many");
    }

    public function test_xss_is_escaped_on_index_and_show(): void
    {
        $user = $this->tgUser('1', [
            'name' => '<script>alert(1)</script>', 'username' => 'x"onmouseover="alert(1)', 'phone' => '"><img src=x onerror=alert(1)>',
        ]);
        $this->conversation('1', 'telegram', [['role' => 'user', 'content' => '<img src=x onerror=alert(2)>']]);
        $admin = $this->admin();

        foreach ([route('admin.telegram-users.index'), route('admin.telegram-users.show', $user),
            route('admin.telegram-users.index', ['q' => '<script>'])] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('<img src=x onerror', $html);
            $this->assertStringNotContainsString('"onmouseover="', $html);
        }
    }

    public function test_nav_active_state_and_breadcrumb(): void
    {
        $user = $this->tgUser('1', ['name' => 'Nav User']);
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Telegram\s*<span[^>]*>\/<\/span>\s*Users/', $html);

        $html = $this->actingAs($admin)->get(route('admin.telegram.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Telegram\s*<span[^>]*>\/<\/span>\s*Bot settings/', $html);
        $this->assertDoesNotMatchRegularExpression('/AI Assistant\s*<span[^>]*>\/<\/span>\s*Telegram bot/', $html);
    }

    public function test_show_many_conversations_media_business_and_missing_records(): void
    {
        $user = $this->tgUser('42', ['name' => 'Many']);
        for ($i = 0; $i < 40; $i++) {
            $this->conversation('42', $i % 2 ? 'business' : 'telegram', [
                ['role' => 'user', 'content' => 'Question '.$i],
                ['role' => 'user', 'type' => 'voice', 'file_id' => 'AwACAgIAAxk_voice'.$i],
                ['role' => 'user', 'type' => 'contact', 'aside' => true],
                ['role' => 'assistant', 'content' => 'Answer '.$i, 'answered' => false],
            ]);
        }
        $this->conversation('42', 'group', [['role' => 'member', 'sender' => 'G', 'content' => 'Group only text']]);
        $this->conversation('42', 'web', [['role' => 'user', 'content' => 'Web chat text']]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()
            ->assertSee('Question 39')->assertSee('Question 10')->assertDontSee('Question 9<', false) // latest 30 of 40
            ->assertSee('Showing the latest 30 of 40 conversations.')
            ->assertSee('not stored')
            ->assertDontSee('Group only text')->assertDontSee('Web chat text');

        $this->actingAs($admin)->get(route('admin.telegram-users.show', 999999))->assertNotFound();
        $this->actingAs($admin)->get('/admin/telegram-users/abc')->assertNotFound();
    }
}
