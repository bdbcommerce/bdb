<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ClientRequest;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: record-only mode (TELEGRAM_BOT_REPLIES=false) edge cases and switching replies back on. */
class TelegramRecordOnlyQaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 100;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-100',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => false,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'reply' => 'Salom! Qanday yordam bera olaman?', 'suggestions' => [], 'offer_callback' => false,
                'answered' => true, 'knowledge_id' => 0, 'topic' => 'x', 'category' => 'other',
            ])]]]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function update(array $update): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++] + $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->assertOk();
    }

    private function message(array $message, int $id = 0, string $key = 'message'): void
    {
        $this->update([$key => $message + [
            'message_id' => $id ?: $this->updateId,
            'chat' => ['id' => 777, 'type' => 'private'],
            'from' => ['id' => 777, 'first_name' => 'Ойбек', 'last_name' => 'Oʻrinov'],
            'date' => time(),
        ]]);
    }

    public function test_no_outgoing_http_for_any_private_payload(): void
    {
        $payloads = [
            ['text' => '/start'], ['text' => '/language'], ['text' => '/help'],
            ['text' => '🇺🇿 Oʻzbekcha'], ['text' => 'Русский'],
            ['contact' => ['phone_number' => '+998901234567', 'user_id' => 777, 'first_name' => 'O']],
            ['contact' => ['phone_number' => '+998901234567', 'user_id' => 1]],
            ['voice' => ['file_id' => 'voice_file_123']], ['sticker' => ['file_id' => 'sticker_123']],
            ['document' => ['file_id' => 'doc_1234567', 'file_name' => 'a.pdf']],
            ['location' => ['latitude' => 41.3, 'longitude' => 69.2]],
            ['text' => ['malformed']], ['text' => ''], ['text' => str_repeat('я', 5000)],
            ['text' => __('chat.tg_request_call', [], 'uz')], ['text' => __('chat.tg_request_call', [], 'ru')], ['text' => __('chat.tg_request_call', [], 'en')],
        ];
        foreach ($payloads as $p) {
            $this->message($p);
        }
        for ($i = 0; $i < 25; $i++) {
            $this->message(['text' => "flood {$i}"]);
        }
        // Missing "from", callback query and an edit.
        $this->update(['message' => ['message_id' => 5000, 'chat' => ['id' => 777, 'type' => 'private'], 'text' => 'no from', 'date' => time()]]);
        $this->update(['callback_query' => ['id' => 'q', 'from' => ['id' => 777], 'data' => 'call']]);
        $this->message(['text' => 'edited'], 5000, 'edited_message');

        Http::assertNothingSent();
        $this->assertSame(0, ChatLead::count());
        $this->assertSame(0, ClientRequest::count());

        $user = TelegramUser::sole();
        $this->assertSame(TelegramUser::STEP_LANGUAGE, $user->step);
        $this->assertNull($user->phone);
        $this->assertNull($user->name);
        $this->assertNull($user->locale);
        $this->assertSame('Ойбек Oʻrinov', $user->profile_name);
        $this->assertSame(count($payloads) + 25 + 1, ChatConversation::sole()->message_count);
    }

    public function test_edits_update_the_recorded_message(): void
    {
        $this->message(['text' => 'Narxi qancha?'], 10);
        $this->message(['text' => 'Narxi qancha? (A4)'], 10, 'edited_message');

        $messages = ChatConversation::sole()->messages;
        $this->assertCount(1, $messages);
        $this->assertSame('Narxi qancha? (A4)', $messages[0]['content']);
        Http::assertNothingSent();
    }

    public function test_edit_of_an_unrecorded_message_is_saved_as_a_plain_message(): void
    {
        $this->message(['text' => 'Salom'], 10);
        $this->message(['text' => 'Old message, now edited'], 3, 'edited_message');

        $messages = ChatConversation::sole()->messages;
        $this->assertCount(2, $messages);
        $this->assertSame('Old message, now edited', $messages[1]['content']);
        // In record-only mode nothing is pending, so it must not be marked as a bot step.
        $this->assertArrayNotHasKey('aside', $messages[1]);
    }

    public function test_staff_reply_and_suggest_still_work(): void
    {
        $this->message(['text' => 'Zebra ZT411 bormi?']);
        $conversation = ChatConversation::sole();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->postJson(route('admin.chat-conversations.suggest', $conversation))
            ->assertOk()->assertJson(['reply' => 'Salom! Qanday yordam bera olaman?']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateContent')
            && $r['contents'][0]['parts'][0]['text'] === 'Zebra ZT411 bormi?');

        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $conversation), ['message' => 'Ha, bor.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] == '777' && $r['text'] === 'Ha, bor.');
    }

    public function test_switching_replies_on_resumes_full_registration(): void
    {
        $this->message(['text' => 'Salom']);
        Http::assertNothingSent();

        config(['services.telegram.replies' => true]);

        $this->message(['text' => 'Narxi?']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['text'] === TelegramBot::LANGUAGE_PROMPT);

        $this->message(['text' => TelegramBot::LANGUAGES['uz']]);
        $user = TelegramUser::sole();
        $this->assertSame(TelegramUser::STEP_NAME, $user->step); // profile_name does not skip the name step
        $this->assertNull($user->name);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['text'] === __('chat.tg_ask_name', [], 'uz'));

        $this->message(['text' => 'Oybek']);
        $this->assertSame(TelegramUser::STEP_PHONE, $user->fresh()->step);
        $this->message(['contact' => ['phone_number' => '+998901234567', 'user_id' => 777]]);
        $user = $user->fresh();
        $this->assertTrue($user->isRegistered());
        $this->assertSame('Oybek', $user->displayName());

        $this->message(['text' => 'Printer narxi qancha?']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));
    }

    public function test_display_name_falls_back_and_admin_pages_render_without_names(): void
    {
        TelegramUser::create(['telegram_id' => '1', 'username' => 'only_user']);
        TelegramUser::create(['telegram_id' => '2']);
        TelegramUser::create(['telegram_id' => '3', 'profile_name' => str_repeat('Ш', 255)]);
        $this->assertSame('@only_user', TelegramUser::firstWhere('telegram_id', '1')->displayName());
        $this->assertNull(TelegramUser::firstWhere('telegram_id', '2')->displayName());

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.telegram-users.index'))->assertOk()->assertSee('@only_user')->assertSee('No name yet');
        foreach (TelegramUser::all() as $u) {
            $this->actingAs($admin)->get(route('admin.telegram-users.show', $u))->assertOk();
        }

        config(['services.telegram.replies' => true]);
        $this->actingAs($admin)->get(route('admin.telegram-users.index'))->assertOk()->assertSee('Step: language');
        $this->actingAs($admin)->get(route('admin.telegram.edit'))->assertOk()->assertSee('Mode: Bot answers customers');
    }

    public function test_profile_name_xss_is_escaped(): void
    {
        $xss = '<script>alert(1)</script>';
        $this->update(['message' => ['message_id' => 1, 'chat' => ['id' => 9, 'type' => 'private'], 'from' => ['id' => 9, 'first_name' => $xss], 'text' => 'hi', 'date' => time()]]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.telegram-users.index'))->assertOk()->assertDontSee($xss, false);
        $this->actingAs($admin)->get(route('admin.chat-conversations.index'))->assertOk()->assertDontSee($xss, false);
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', ChatConversation::sole()))->assertOk()->assertDontSee($xss, false);
    }
}
