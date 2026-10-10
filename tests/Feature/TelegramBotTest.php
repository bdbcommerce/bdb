<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\TelegramUser;
use App\Services\ChatAssistant;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private const SALES_CHAT = '-1001234567890';

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => self::SALES_CHAT,
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
        ]);
    }

    private function fakeApis(bool $answered = true, bool $offerCallback = false, string $reply = 'Asl Belgi is the national marking system.'): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'reply' => $reply,
                    'suggestions' => ['What equipment do you offer?'],
                    'offer_callback' => $offerCallback,
                    'answered' => $answered,
                ])]]]]],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function update(array $message, string $chatType = 'private', int $chatId = 555): array
    {
        return [
            'update_id' => $this->updateId++,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $chatId, 'type' => $chatType],
                'from' => ['id' => $chatId, 'first_name' => 'Aziz', 'username' => 'aziz_uz', 'language_code' => 'ru'],
                'date' => time(),
            ] + $message,
        ];
    }

    private function webhook(array $update, ?string $secret = self::SECRET)
    {
        return $this->postJson('/telegram/webhook', $update, $secret ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : []);
    }

    private function send(array $message)
    {
        return $this->webhook($this->update($message))->assertOk();
    }

    /** @return array<int, Request> sendMessage requests to the given chat */
    private function sentTo(int|string $chatId): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === (string) $chatId)
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    private function lastSent(): Request
    {
        return collect($this->sentTo(555))->last();
    }

    private function registered(string $locale = 'ru'): TelegramUser
    {
        return TelegramUser::create([
            'telegram_id' => '555', 'username' => 'aziz_uz', 'locale' => $locale, 'step' => TelegramUser::STEP_DONE,
            'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);
    }

    // Security / routing ---------------------------------------------------------

    public function test_requests_without_the_secret_are_rejected(): void
    {
        $this->fakeApis();

        $this->webhook($this->update(['text' => 'Hi']), null)->assertForbidden();
        $this->webhook($this->update(['text' => 'Hi']), 'wrong-secret')->assertForbidden();

        config(['services.telegram.webhook_secret' => null]);
        $this->webhook($this->update(['text' => 'Hi']), '')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_group_messages_are_ignored(): void
    {
        $this->fakeApis();

        $this->webhook($this->update(['text' => 'Lead taken, calling now'], 'supergroup', (int) self::SALES_CHAT))->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, TelegramUser::count());
    }

    public function test_redelivered_update_is_handled_once(): void
    {
        $this->fakeApis();
        $update = $this->update(['text' => '/start']);

        $this->webhook($update)->assertOk();
        $this->webhook($update)->assertOk();

        $this->assertCount(1, $this->sentTo(555));
    }

    // Registration ---------------------------------------------------------------

    public function test_first_message_asks_for_language_in_all_three_languages(): void
    {
        $this->fakeApis();

        $this->send(['text' => '/start']);

        $sent = $this->lastSent();
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $sent['text']);
        $this->assertSame(['🇺🇿 Oʻzbekcha', '🇷🇺 Русский', '🇬🇧 English'], array_column($sent['reply_markup']['keyboard'][0], 'text'));
        $this->assertSame(TelegramUser::STEP_LANGUAGE, TelegramUser::sole()->step);
    }

    public function test_full_registration_in_uzbek(): void
    {
        $this->fakeApis();

        $this->send(['text' => '/start']);
        $this->send(['text' => 'Oʻzbekcha']);
        $this->assertSame(__('chat.tg_ask_name', [], 'uz'), $this->lastSent()['text']);

        $this->send(['text' => 'Aziz Karimov']);
        $askPhone = $this->lastSent();
        $this->assertSame(__('chat.tg_ask_phone', ['name' => 'Aziz Karimov'], 'uz'), $askPhone['text']);
        $this->assertSame(
            ['text' => __('chat.tg_share_phone', [], 'uz'), 'request_contact' => true],
            $askPhone['reply_markup']['keyboard'][0][0],
        );

        $this->send(['contact' => ['phone_number' => '998901234567', 'first_name' => 'Aziz', 'user_id' => 555]]);
        $this->assertSame(__('chat.tg_registered', [], 'uz'), $this->lastSent()['text']);

        $user = TelegramUser::sole();
        $this->assertSame('uz', $user->locale);
        $this->assertSame('Aziz Karimov', $user->name);
        $this->assertSame('+998901234567', $user->phone);
        $this->assertSame('aziz_uz', $user->username);
        $this->assertTrue($user->isRegistered());

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));
    }

    public function test_language_must_be_picked_from_the_buttons(): void
    {
        $this->fakeApis();

        $this->send(['text' => 'Hello, what is Asl Belgi?']);
        $this->send(['text' => 'Deutsch']);

        $this->assertCount(2, $this->sentTo(555));
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastSent()['text']);
        $this->assertNull(TelegramUser::sole()->locale);
    }

    public function test_invalid_names_are_rejected(): void
    {
        $this->fakeApis();
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_NAME]);

        foreach (['A', '/help', '12345', str_repeat('a', 61)] as $name) {
            $this->send(['text' => $name]);
            $this->assertSame(__('chat.tg_name_invalid', [], 'en'), $this->lastSent()['text'], $name);
        }
        $this->send(['photo' => [['file_id' => 'photo_small_1', 'width' => 90]]]);
        $this->assertSame(__('chat.tg_name_invalid', [], 'en'), $this->lastSent()['text']);

        $this->assertNull(TelegramUser::sole()->name);
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_uzbek_apostrophe_variants_are_accepted_in_names(): void
    {
        $this->fakeApis();

        foreach (['O‘tkir', 'G`ulnora', 'Oʻktam', "O'g'iloy", 'Shoʼira'] as $name) {
            TelegramUser::query()->delete();
            TelegramUser::create(['telegram_id' => '555', 'locale' => 'uz', 'step' => TelegramUser::STEP_NAME]);

            $this->send(['text' => $name]);

            $this->assertSame($name, TelegramUser::sole()->name, $name);
            $this->assertSame(TelegramUser::STEP_PHONE, TelegramUser::sole()->step);
        }
    }

    public function test_start_during_registration_repeats_the_current_step(): void
    {
        $this->fakeApis();
        $user = TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_NAME]);

        $this->send(['text' => '/start']);
        $this->assertSame(__('chat.tg_ask_name', [], 'en'), $this->lastSent()['text']);

        $user->update(['name' => 'Aziz', 'step' => TelegramUser::STEP_PHONE]);
        $this->send(['text' => '/start']);
        $this->assertSame(__('chat.tg_ask_phone', ['name' => 'Aziz'], 'en'), $this->lastSent()['text']);
        $this->assertTrue($this->lastSent()['reply_markup']['keyboard'][0][0]['request_contact']);

        $this->assertSame(TelegramUser::STEP_PHONE, $user->fresh()->step);
    }

    public function test_only_the_customers_own_telegram_number_is_accepted(): void
    {
        $this->fakeApis();
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_PHONE, 'name' => 'Aziz']);

        $this->send(['text' => '+998901234567']);
        $this->assertSame(__('chat.tg_phone_not_own', [], 'ru'), $this->lastSent()['text']);

        $this->send(['contact' => ['phone_number' => '998907777777', 'first_name' => 'Friend', 'user_id' => 999]]);
        $this->assertSame(__('chat.tg_phone_not_own', [], 'ru'), $this->lastSent()['text']);

        $this->send(['contact' => ['phone_number' => '998907777777', 'first_name' => 'No account']]);
        $this->assertSame(__('chat.tg_phone_not_own', [], 'ru'), $this->lastSent()['text']);

        $user = TelegramUser::sole();
        $this->assertNull($user->phone);
        $this->assertFalse($user->isRegistered());
    }

    public function test_unregistered_customers_cannot_reach_the_assistant(): void
    {
        $this->fakeApis();
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_PHONE, 'name' => 'Aziz']);

        $this->send(['text' => 'Сколько стоит принтер?']);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_language_command_changes_language_and_keeps_registration(): void
    {
        $this->fakeApis();
        $this->registered('ru');

        $this->send(['text' => '/language']);
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastSent()['text']);

        $this->send(['text' => 'English']);
        $this->assertSame(__('chat.tg_language_changed', [], 'en'), $this->lastSent()['text']);

        $user = TelegramUser::sole();
        $this->assertSame('en', $user->locale);
        $this->assertSame(TelegramUser::STEP_DONE, $user->step);
        $this->assertSame('+998901234567', $user->phone);
    }

    // Chat -----------------------------------------------------------------------

    public function test_question_is_answered_briefly_and_logged(): void
    {
        $this->fakeApis();
        $this->registered('ru');

        $this->send(['text' => 'Что такое Asl Belgi?']);

        $sent = $this->lastSent();
        $this->assertSame('Asl Belgi is the national marking system.', $sent['text']);
        $this->assertSame('💡 What equipment do you offer?', $sent['reply_markup']['keyboard'][0][0]['text']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateContent')
            && str_contains($r['system_instruction']['parts'][0]['text'], 'At most 2 short sentences'));

        $conversation = ChatConversation::sole();
        $this->assertSame('telegram', $conversation->channel);
        $this->assertSame('555', $conversation->external_id);
        $this->assertSame('ru', $conversation->locale);
        $this->assertSame(2, $conversation->message_count);
        $this->assertSame('Aziz', $conversation->telegramUser->name);
    }

    public function test_long_replies_are_cut_at_a_sentence_end(): void
    {
        $long = str_repeat('BDB supplies barcode scanners and printers. ', 20);
        $this->fakeApis(reply: $long);
        $this->registered();

        $this->send(['text' => 'What do you sell?']);

        $text = $this->lastSent()['text'];
        $this->assertLessThanOrEqual(ChatAssistant::BRIEF_MAX, mb_strlen($text));
        $this->assertStringEndsWith('printers.', $text);
    }

    public function test_follow_up_questions_reuse_the_conversation_and_send_history(): void
    {
        $this->fakeApis();
        $this->registered();

        $this->send(['text' => 'First question']);
        $this->send(['photo' => [['file_id' => 'p1_small'], ['file_id' => 'p1_large']]]);
        $this->send(['text' => 'Second question']);

        $this->assertSame(1, ChatConversation::count());

        // Media is not sent to the AI.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateContent')
            && count($r['contents']) === 3
            && $r['contents'][0]['parts'][0]['text'] === 'First question'
            && $r['contents'][2]['parts'][0]['text'] === 'Second question');
    }

    public function test_staff_replies_are_sent_to_the_ai_as_the_assistant_side(): void
    {
        $this->fakeApis();
        $this->registered();

        $this->send(['text' => 'Is the scanner in stock?']);
        ChatConversation::sole()->appendAdminMessage('Yes, 5 units in Tashkent.', 'Admin');
        $this->send(['text' => 'Great, how fast is delivery?']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateContent')
            && count($r['contents']) === 4
            && $r['contents'][2] === ['role' => 'model', 'parts' => [['text' => 'Yes, 5 units in Tashkent.']]]
            && $r['contents'][3]['parts'][0]['text'] === 'Great, how fast is delivery?');
    }

    public function test_media_is_stored_by_type_and_file_id_without_a_reply(): void
    {
        $this->fakeApis();
        $this->registered();

        $this->send(['photo' => [['file_id' => 'photo_small_1'], ['file_id' => 'photo_large_1']], 'caption' => 'my label']);
        $this->send(['voice' => ['file_id' => 'voice_1', 'duration' => 3, 'mime_type' => 'audio/ogg']]);
        $this->send(['animation' => ['file_id' => 'anim_1'], 'document' => ['file_id' => 'anim_1']]);
        $this->send(['document' => ['file_id' => 'doc_1', 'file_name' => 'invoice.pdf']]);
        $this->send(['location' => ['latitude' => 41.3, 'longitude' => 69.2]]);

        $this->assertSame([], $this->sentTo(555));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));

        $this->assertSame([
            ['role' => 'user', 'type' => 'photo', 'file_id' => 'photo_large_1'],
            ['role' => 'user', 'type' => 'voice', 'file_id' => 'voice_1'],
            ['role' => 'user', 'type' => 'animation', 'file_id' => 'anim_1'],
            ['role' => 'user', 'type' => 'document', 'file_id' => 'doc_1'],
            ['role' => 'user', 'type' => 'location', 'file_id' => null],
        ], ChatConversation::sole()->messages);
    }

    public function test_callback_offer_shows_request_call_button_and_creates_lead(): void
    {
        $this->fakeApis(offerCallback: true);
        $this->registered('ru');

        $this->send(['text' => 'Нужен RFID для склада']);

        $offer = $this->lastSent();
        $this->assertStringContainsString(__('chat.tg_call_hint', [], 'ru'), $offer['text']);
        $this->assertSame(['text' => __('chat.tg_request_call', [], 'ru')], $offer['reply_markup']['keyboard'][0][0]);

        $this->send(['text' => __('chat.tg_request_call', [], 'ru')]);

        $lead = ChatLead::sole();
        $this->assertSame('telegram', $lead->channel);
        $this->assertSame('Aziz', $lead->name);
        $this->assertSame('+998901234567', $lead->phone);
        $this->assertSame('Нужен RFID для склада', $lead->topic);
        $this->assertSame('https://t.me/aziz_uz', $lead->page_url);
        $this->assertSame($lead->id, ChatConversation::sole()->chat_lead_id);

        [$alert] = $this->sentTo(self::SALES_CHAT);
        $this->assertStringContainsString('Telegram bot', $alert['text']);
        $this->assertStringContainsString('+998901234567', $alert['text']);

        $thanks = $this->lastSent();
        $this->assertSame(__('chat.tg_thanks', [], 'ru'), $thanks['text']);
        $this->assertTrue($thanks['reply_markup']['remove_keyboard']);
    }

    public function test_repeated_call_requests_create_one_lead(): void
    {
        $this->fakeApis(offerCallback: true);
        $this->registered('ru');

        $this->send(['text' => 'Нужен RFID']);
        $this->send(['text' => __('chat.tg_request_call', [], 'ru')]);
        $this->send(['text' => __('chat.tg_request_call', [], 'ru')]);

        $this->assertSame(1, ChatLead::count());
        $this->assertCount(1, $this->sentTo(self::SALES_CHAT));
        $this->assertSame(__('chat.tg_thanks', [], 'ru'), $this->lastSent()['text']);

        $this->travel(2)->hours();
        $this->send(['text' => __('chat.tg_request_call', [], 'ru')]);
        $this->assertSame(2, ChatLead::count());
    }

    public function test_whitespace_only_text_is_ignored_not_stored_as_media(): void
    {
        $this->fakeApis();
        $this->registered();

        $this->send(['text' => '   ']);

        $this->assertSame([], $this->sentTo(555));
        $this->assertSame(0, ChatConversation::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));
    }

    public function test_shorten_never_exceeds_the_limit(): void
    {
        $max = ChatAssistant::BRIEF_MAX;

        foreach ([
            str_repeat('a', $max + 1),
            'See https://bdb.uz/'.str_repeat('x', $max),
            str_repeat('слово ', 100),
            str_repeat('Short one. ', 40),
        ] as $text) {
            $this->assertLessThanOrEqual($max, mb_strlen(ChatAssistant::shorten($text, $max)), mb_substr($text, 0, 30));
        }

        $this->assertSame(str_repeat('a', $max), ChatAssistant::shorten(str_repeat('a', $max), $max));
        $this->assertStringEndsWith('слово…', ChatAssistant::shorten(str_repeat('слово ', 100), $max));
    }

    public function test_start_for_registered_customer_sends_welcome(): void
    {
        $this->fakeApis();
        $this->registered('en');

        $this->send(['text' => '/start']);

        $sent = $this->lastSent();
        $this->assertSame(__('chat.tg_welcome', [], 'en'), $sent['text']);
        $this->assertSame('💡 '.__('chat.starters', [], 'en')[0], $sent['reply_markup']['keyboard'][0][0]['text']);
    }

    // Command --------------------------------------------------------------------

    public function test_set_webhook_command_requires_https_and_registers_secret(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        config(['app.url' => 'http://localhost']);
        url()->forceRootUrl('http://localhost');
        $this->artisan('telegram:webhook', ['action' => 'set'])->assertFailed();

        url()->forceRootUrl('https://bdb.uz');
        url()->forceScheme('https');
        $this->artisan('telegram:webhook', ['action' => 'set'])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === 'https://bdb.uz/telegram/webhook'
            && $r['secret_token'] === self::SECRET
            && $r['allowed_updates'] === ['message']);
    }
}
