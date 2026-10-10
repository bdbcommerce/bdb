<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\ChatAssistant;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Edge cases of the Telegram bot: unusual updates, out-of-order registration
 * messages, names in Cyrillic / Uzbek Latin, brief-reply shortening, media
 * detection, admin views and the media proxy.
 */
class TelegramEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private const TOKEN = '123456789:AAEdgeCaseTokenNeverShown';

    private int $updateId = 100;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => self::TOKEN,
            'services.telegram.chat_id' => '-100999',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
        ]);
    }

    private function fakeApis(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'reply' => 'Short answer.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
                ])]]]]],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function webhook(array $update)
    {
        return $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++] + $update, [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk();
    }

    private function send(array $message, ?array $from = ['id' => 555, 'username' => 'aziz_uz'])
    {
        $base = ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'], 'date' => time()];
        if ($from !== null) {
            $base['from'] = $from;
        }

        return $this->webhook(['message' => $base + $message]);
    }

    private function lastText(): ?string
    {
        $request = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '555')->last();

        return $request ? $request[0]['text'] : null;
    }

    private function sentCount(): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->count();
    }

    private function user(string $step, array $extra = []): TelegramUser
    {
        return TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => $step] + $extra);
    }

    private function registered(): TelegramUser
    {
        return $this->user(TelegramUser::STEP_DONE, ['name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // Updates without a message ----------------------------------------------------

    public function test_updates_without_a_message_are_ignored(): void
    {
        $this->fakeApis();

        $this->webhook(['edited_message' => ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'], 'text' => 'edited']]);
        $this->webhook(['callback_query' => ['id' => '1', 'from' => ['id' => 555]]]);
        $this->webhook([]);

        Http::assertNothingSent();
        $this->assertSame(0, TelegramUser::count());
    }

    // Out-of-order registration ------------------------------------------------------

    public function test_contact_before_choosing_a_language_repeats_the_language_prompt(): void
    {
        $this->fakeApis();

        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);

        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastText());
        $user = TelegramUser::sole();
        $this->assertSame(TelegramUser::STEP_LANGUAGE, $user->step);
        $this->assertNull($user->phone);
    }

    public function test_contact_or_media_at_the_name_step_asks_for_the_name_again(): void
    {
        $this->fakeApis();
        $this->user(TelegramUser::STEP_NAME);

        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);
        $this->assertSame(__('chat.tg_name_invalid', [], 'en'), $this->lastText());

        $this->send(['voice' => ['file_id' => 'voice_file_123']]);
        $this->assertSame(__('chat.tg_name_invalid', [], 'en'), $this->lastText());

        $user = TelegramUser::sole();
        $this->assertSame(TelegramUser::STEP_NAME, $user->step);
        $this->assertNull($user->phone);
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_contact_without_a_sender_is_rejected(): void
    {
        $this->fakeApis();
        $this->user(TelegramUser::STEP_PHONE, ['name' => 'Aziz']);

        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]], from: null);

        $this->assertSame(__('chat.tg_phone_not_own', [], 'en'), $this->lastText());
        $this->assertFalse(TelegramUser::sole()->isRegistered());
    }

    public function test_own_contact_with_plus_and_string_user_id_registers_once_normalised(): void
    {
        $this->fakeApis();
        $this->user(TelegramUser::STEP_PHONE, ['name' => 'Aziz']);

        $this->send(['contact' => ['phone_number' => '+998901234567', 'user_id' => '555']]);

        $user = TelegramUser::sole();
        $this->assertTrue($user->isRegistered());
        $this->assertSame('+998901234567', $user->phone);
        $this->assertSame(__('chat.tg_registered', [], 'en'), $this->lastText());
    }

    public function test_language_command_mid_registration_keeps_the_typed_name(): void
    {
        $this->fakeApis();
        $this->user(TelegramUser::STEP_PHONE, ['name' => 'Aziz']);

        $this->send(['text' => '/language']);
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastText());

        $this->send(['text' => 'Русский']);
        $this->assertSame(__('chat.tg_ask_phone', ['name' => 'Aziz'], 'ru'), $this->lastText());

        $user = TelegramUser::sole();
        $this->assertSame('ru', $user->locale);
        $this->assertSame(TelegramUser::STEP_PHONE, $user->step);
        $this->assertSame('Aziz', $user->name);
        $this->assertFalse($user->isRegistered());
    }

    // Names ------------------------------------------------------------------------

    public static function validNames(): array
    {
        return [
            'uzbek latin okina' => ['Oʻktam Gʻulomov', 'Oʻktam Gʻulomov'],
            'uzbek latin apostrophe' => ["O'tkir", "O'tkir"],
            'uzbek cyrillic' => ['Ғайрат Қодиров', 'Ғайрат Қодиров'],
            'uzbek cyrillic h' => ['Шаҳзода', 'Шаҳзода'],
            'russian' => ['Азиз', 'Азиз'],
            'hyphen' => ['Anna-Maria', 'Anna-Maria'],
            'initials' => ['J. R.', 'J. R.'],
            'collapsed spaces' => ["  Ali   Vali\n ", 'Ali Vali'],
            'two letters' => ['Ab', 'Ab'],
            'sixty letters' => [str_repeat('a', 60), str_repeat('a', 60)],
        ];
    }

    #[DataProvider('validNames')]
    public function test_valid_names_are_accepted(string $input, string $stored): void
    {
        $this->fakeApis();
        $this->user(TelegramUser::STEP_NAME);

        $this->send(['text' => $input]);

        $user = TelegramUser::sole();
        $this->assertSame(TelegramUser::STEP_PHONE, $user->step);
        $this->assertSame($stored, $user->name);
        $this->assertSame(__('chat.tg_ask_phone', ['name' => $stored], 'en'), $this->lastText());
    }

    public function test_names_with_emoji_or_digits_are_rejected(): void
    {
        $this->fakeApis();
        $this->user(TelegramUser::STEP_NAME);

        foreach (['😀Ali', 'Ali😀', 'Ali2', '-Ali', '<b>Ali</b>'] as $name) {
            $this->send(['text' => $name]);
            $this->assertSame(__('chat.tg_name_invalid', [], 'en'), $this->lastText(), $name);
        }

        $this->assertNull(TelegramUser::sole()->name);
    }

    // Brief replies ------------------------------------------------------------------

    public function test_shorten_keeps_text_at_or_under_the_limit(): void
    {
        $exact = str_repeat('a', ChatAssistant::BRIEF_MAX);

        $this->assertSame($exact, ChatAssistant::shorten($exact, ChatAssistant::BRIEF_MAX));
        $this->assertSame('Hello.', ChatAssistant::shorten("  Hello.\n", ChatAssistant::BRIEF_MAX));
        $this->assertSame('', ChatAssistant::shorten('   ', ChatAssistant::BRIEF_MAX));
    }

    public function test_shorten_cuts_cyrillic_at_a_sentence_end(): void
    {
        $text = str_repeat('Мы поставляем сканеры штрихкодов и принтеры этикеток. ', 10);

        $short = ChatAssistant::shorten($text, ChatAssistant::BRIEF_MAX);

        $this->assertLessThanOrEqual(ChatAssistant::BRIEF_MAX, mb_strlen($short));
        $this->assertStringEndsWith('этикеток.', $short);
        $this->assertTrue(mb_check_encoding($short, 'UTF-8'));
    }

    public function test_shorten_without_sentence_end_cuts_at_a_word_with_ellipsis(): void
    {
        $short = ChatAssistant::shorten(str_repeat('слово ', 80), ChatAssistant::BRIEF_MAX);

        $this->assertLessThanOrEqual(ChatAssistant::BRIEF_MAX, mb_strlen($short));
        $this->assertStringEndsWith('слово…', $short);
    }

    public function test_shorten_does_not_treat_dots_inside_urls_as_sentence_ends(): void
    {
        $text = 'Visit https://bdb.uz/ru/brands for details '.str_repeat('about scanners and printers ', 12);

        $short = ChatAssistant::shorten($text, ChatAssistant::BRIEF_MAX);

        $this->assertStringContainsString('https://bdb.uz/ru/brands', $short);
        $this->assertStringEndsWith('…', $short);
        $this->assertLessThanOrEqual(ChatAssistant::BRIEF_MAX, mb_strlen($short));
    }

    public function test_brief_mode_is_not_applied_to_the_website_chat(): void
    {
        $long = str_repeat('BDB supplies barcode scanners and printers. ', 20);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'reply' => $long, 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
            ])]]]]],
        ])]);

        $reply = app(ChatAssistant::class)->reply([['role' => 'user', 'content' => 'Hi']], 'en');

        $this->assertSame($long, $reply['reply']);
    }

    // Media detection ----------------------------------------------------------------

    public function test_media_types_are_detected_and_captions_are_not_answered(): void
    {
        $this->fakeApis();
        $this->registered();

        $this->send(['photo' => [['file_id' => 's', 'width' => 90], ['file_id' => 'm', 'width' => 320], ['file_id' => 'l', 'width' => 1280]], 'caption' => 'What is this?']);
        $this->send(['animation' => ['file_id' => 'gif_1'], 'document' => ['file_id' => 'gif_1']]);
        $this->send(['sticker' => ['file_id' => 'sticker_1']]);
        $this->send(['video_note' => ['file_id' => 'circle_1']]);
        $this->send(['video' => ['file_id' => 'video_1'], 'caption' => 'look']);
        $this->send(['contact' => ['phone_number' => '998907777777', 'user_id' => 999]]);
        $this->send(['dice' => ['emoji' => '🎲', 'value' => 3]]);

        $this->assertSame(0, $this->sentCount());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));

        $messages = ChatConversation::sole()->messages;
        $this->assertSame(
            [['photo', 'l'], ['animation', 'gif_1'], ['sticker', 'sticker_1'], ['video_note', 'circle_1'], ['video', 'video_1'], ['contact', null], ['dice', null]],
            array_map(fn ($m) => [$m['type'], $m['file_id']], $messages),
        );
        $this->assertSame([], ChatConversation::sole()->textMessages());
        $this->assertSame(7, ChatConversation::sole()->message_count);
    }

    public function test_blank_text_from_a_registered_customer_gets_no_reply_and_no_ai_call(): void
    {
        $this->fakeApis();
        $this->registered();

        $this->send(['text' => "   \n "]);

        $this->assertSame(0, $this->sentCount());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));
    }

    public function test_too_many_messages_are_throttled(): void
    {
        $this->fakeApis();
        $this->registered();

        for ($i = 0; $i < 16; $i++) {
            $this->send(['sticker' => ['file_id' => 'sticker_'.$i]]);
        }

        $this->assertSame(__('chat.tg_too_fast', [], 'en'), $this->lastText());
        $this->assertCount(15, ChatConversation::sole()->messages);
    }

    // Admin views --------------------------------------------------------------------

    public function test_admin_views_handle_web_orphan_and_empty_conversations(): void
    {
        $web = ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'locale' => 'uz', 'page' => 'Bosh sahifa', 'message_count' => 2, 'unanswered_count' => 1,
            'messages' => [['role' => 'user', 'content' => 'Salom, oʻquvchi'], ['role' => 'assistant', 'content' => 'Assalomu alaykum', 'answered' => false]],
        ]);
        $orphan = ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '777', 'locale' => 'ru', 'message_count' => 2,
            'messages' => [['role' => 'user', 'type' => 'sticker', 'file_id' => 'CAACAgIAAxkBAAIB_st'], ['role' => 'user', 'type' => 'other', 'file_id' => null]],
        ]);
        $empty = ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '778', 'locale' => 'en', 'messages' => [],
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $web))->assertOk()
            ->assertSee('Website')->assertSee('Salom, oʻquvchi')->assertDontSee('Telegram customer');

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $orphan))->assertOk()
            ->assertSee('Telegram bot')->assertSee('Sticker')
            ->assertSee(route('admin.telegram.media', 'CAACAgIAAxkBAAIB_st'), false)
            ->assertSee('not stored')->assertDontSee(self::TOKEN);

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $empty))->assertOk();

        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['channel' => 'telegram']))->assertOk()
            ->assertSee('[Sticker]')->assertDontSee('Salom, oʻquvchi');

        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['filter' => 'nonsense', 'channel' => 'nonsense']))->assertOk()
            ->assertSee('Salom, oʻquvchi')->assertSee('[Sticker]');
    }

    public function test_settings_page_with_status_check_never_shows_the_token(): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bdb_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]]),
        ]);
        $this->user(TelegramUser::STEP_NAME);

        $this->actingAs($this->admin())->get(route('admin.telegram.edit', ['check' => 1]))->assertOk()
            ->assertSee('@bdb_bot')->assertSee('Not connected')
            ->assertSee('0 registered, 1 started')
            ->assertDontSee(self::TOKEN)->assertDontSee(self::SECRET);
    }

    public function test_settings_actions_are_admin_only(): void
    {
        Http::fake();

        foreach (['admin.telegram.connect', 'admin.telegram.disconnect', 'admin.telegram.test'] as $route) {
            $this->post(route($route))->assertRedirect(route('admin.login'));
        }
        $this->get(route('admin.telegram.edit'))->assertRedirect(route('admin.login'));

        $user = User::factory()->create(['is_admin' => false]);
        foreach (['admin.telegram.connect', 'admin.telegram.disconnect', 'admin.telegram.test'] as $route) {
            $this->actingAs($user)->post(route($route))->assertRedirect();
        }

        Http::assertNothingSent();
    }

    // Media proxy --------------------------------------------------------------------

    public function test_proxy_escapes_quotes_in_the_file_name(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/a"b.pdf']]),
            'api.telegram.org/file/*' => Http::response('%PDF', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.telegram.media', 'BQACAgIAAxkBAAIB_doc'))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame('attachment; filename="a\"b.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString(self::TOKEN, json_encode($response->headers->all()));
    }

    public function test_proxy_serves_svg_inline_only_inside_a_sandbox(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'stickers/x.svg']]),
            'api.telegram.org/file/*' => Http::response('<svg onload="alert(1)"/>', 200, ['Content-Type' => 'image/svg+xml']),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.telegram.media', 'CAACAgIAAxkBAAIB_svg'))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_proxy_connection_failure_logs_without_the_token(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.self::TOKEN.'/getFile'));

        $response = $this->actingAs($this->admin())->get(route('admin.telegram.media', 'AgACAgIAAxkBAAIB_photo'))->assertNotFound();

        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logged));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_proxy_rejects_malformed_file_ids(): void
    {
        Http::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/telegram/media/short')->assertNotFound();
        $this->actingAs($admin)->get('/admin/telegram/media/'.rawurlencode('../../etc/passwd_xx'))->assertNotFound();

        Http::assertNothingSent();
    }
}
