<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: collapsed sidebar groups on several pages, and re-ticking "ask registration" in the bot chat. */
class QaSidebarAndRetickTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'qa_secret_123';

    private int $updateId = 1;

    private int $msgId = 100;

    private bool $failSends = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => false,
        ]);
        Http::fake(fn () => $this->failSends
            ? Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden'], 403)
            : Http::response(['ok' => true, 'result' => ['message_id' => 9000 + $this->msgId]]));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function openGroups(string $html): array
    {
        preg_match_all('/<ul id="nav-group-([a-z]+)"([^>]*)>/', $html, $m, PREG_SET_ORDER);

        return collect($m)->reject(fn ($x) => str_contains($x[2], 'x-cloak'))->map(fn ($x) => $x[1])->values()->all();
    }

    public function test_each_section_page_opens_only_its_group(): void
    {
        $admin = $this->admin();
        $pages = [
            'admin.dashboard' => [],
            'admin.company-info.edit' => ['company'],
            'admin.banners.index' => ['company'],
            'admin.brands.index' => ['catalog'],
            'admin.customers.index' => ['catalog'],
            'admin.articles.index' => ['content'],
            'admin.article-categories.index' => ['content'],
            'admin.client-requests.index' => ['assistant'],
            'admin.chat-leads.index' => ['assistant'],
            'admin.ai-models.index' => ['assistant'],
            'admin.awaiting-replies.index' => ['telegram'],
            'admin.telegram-users.index' => ['telegram'],
            'admin.telegram.edit' => ['telegram'],
        ];
        foreach ($pages as $route => $open) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
            $this->assertSame($open, $this->openGroups($html), $route);
            // Server-rendered aria-expanded matches.
            preg_match_all('/aria-expanded="(true|false)"\s+aria-controls="nav-group-([a-z]+)"/', $html, $a, PREG_SET_ORDER);
            $this->assertCount(5, $a, $route);
            foreach ($a as [$all, $state, $key]) {
                $this->assertSame(in_array($key, $open, true) ? 'true' : 'false', $state, "$route $key");
            }
            $this->assertStringNotContainsString("'bdb.admin.nav.company'", $html);
        }
    }

    public function test_badges_render_on_collapsed_groups(): void
    {
        ChatLead::create(['name' => 'Ali', 'phone' => '+998901112233', 'is_handled' => false, 'channel' => 'web', 'locale' => 'ru']);
        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();

        // Group "AI Assistant" collapsed and its header carries the badge (shown while closed).
        $this->assertNotContains('assistant', $this->openGroups($html));
        $this->assertMatchesRegularExpression('/AI Assistant<\/span>\s*<span x-show="! open" x-cloak[^>]*>\s*1\s*<span class="sr-only">new<\/span>/', $html);
    }

    public function test_mobile_drawer_markup_still_present(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('x-trap.noscroll="sidebarOpen && !isDesktop"', $html);
        $this->assertStringContainsString('Close menu', $html);
        $this->assertStringContainsString('w-72', $html);
    }

    private function hook(array $update): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++] + $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function botChat(array $message): void
    {
        $this->hook(['message' => $message + [
            'message_id' => ++$this->msgId,
            'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'language_code' => 'ru'],
            'date' => time(),
        ]]);
    }

    private function setAsk(bool $ask): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.telegram-users.update', TelegramUser::sole()), $ask ? ['ask_registration' => '1'] : [])
            ->assertSessionHasNoErrors();
    }

    private function lastText(): ?string
    {
        $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($p) => $p[0])->values()->all();

        return last($sent)['text'] ?? null;
    }

    public function test_reticking_restarts_the_bot_chat_flow(): void
    {
        $this->botChat(['text' => 'Salom']);
        $this->setAsk(true);
        $this->botChat(['text' => 'Narx?']);
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastText());
        $this->botChat(['text' => 'ru']);
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);

        $this->setAsk(false);
        $this->assertSame(TelegramUser::STEP_LANGUAGE, TelegramUser::sole()->step);
        $this->setAsk(true);

        $this->botChat(['text' => 'Narxlar']);
        $user = TelegramUser::sole();
        $this->assertNull($user->name, 'Leftover step in bot chat: question saved as the name');
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastText());
        // The real question stays a real question (not aside).
        $entry = collect(ChatConversation::where('channel', 'telegram')->sole()->messages)->firstWhere('content', 'Narxlar');
        $this->assertArrayNotHasKey('aside', $entry);
    }

    public function test_saving_without_changing_the_flag_keeps_the_step(): void
    {
        $this->botChat(['text' => 'Salom']);
        $this->setAsk(true);
        $this->botChat(['text' => 'ru']);
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);

        // Admin edits notes while the flag stays ticked: no restart.
        $this->actingAs($this->admin())->put(route('admin.telegram-users.update', TelegramUser::sole()), ['ask_registration' => '1', 'notes' => 'VIP'])
            ->assertSessionHasNoErrors();
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);
    }

    public function test_fail_sends_toggle_really_returns_403(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => '777', 'name' => 'BDB', 'can_reply' => true]);
        $biz = fn (string $text) => $this->hook(['business_message' => [
            'business_connection_id' => 'conn-1', 'message_id' => ++$this->msgId, 'text' => $text,
            'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'language_code' => 'ru'], 'date' => time(),
        ]]);
        $biz('Salom');
        $this->setAsk(true);
        $this->failSends = true;
        $biz('Hello');
        $this->failSends = false;

        $last = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->last();
        $this->assertSame(403, $last[1]->status());
        $this->assertSame(TelegramUser::STEP_LANGUAGE, TelegramUser::sole()->step);
        $this->assertNull(collect(ChatConversation::where('channel', 'business')->sole()->messages)->firstWhere('role', 'bot'));
    }
}
