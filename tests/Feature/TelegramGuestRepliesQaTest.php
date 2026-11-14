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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** QA edge cases for guest vs registered replies in the Telegram bot (replies on). */
class TelegramGuestRepliesQaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    private int $messageId = 1;

    private string $aiReply = 'We sell Zebra and Honeywell scanners.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-100123',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake(fn (Request $r) => str_contains($r->url(), 'generativelanguage')
            ? Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'reply' => $this->aiReply, 'suggestions' => [], 'offer_callback' => false, 'answered' => true, 'topic' => 'scanners',
            ])]]]]]])
            : Http::response(['ok' => true, 'result' => []]));
    }

    private function send(array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'message_id' => $this->messageId++,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz'],
            'date' => time(),
        ] + $message], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function last(): Request
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '555')->last()[0];
    }

    private function buttons(): array
    {
        return collect($this->last()['reply_markup']['keyboard'] ?? [])->flatten(1)->pluck('text')->all();
    }

    public static function locales(): array
    {
        return ['uz' => ['uz', '🇺🇿 Oʻzbekcha'], 'ru' => ['ru', '🇷🇺 Русский'], 'en' => ['en', '🇬🇧 English']];
    }

    #[DataProvider('locales')]
    public function test_hints_name_the_real_button_labels(string $locale): void
    {
        $register = __('chat.tg_register', [], $locale);
        foreach (['tg_guest_welcome', 'tg_guest_invite', 'tg_registration_later'] as $key) {
            $this->assertStringContainsString($register, __("chat.$key", [], $locale), "$locale $key");
        }
        $call = __('chat.tg_request_call', [], $locale);
        foreach (['tg_call_hint', 'tg_guest_call_hint'] as $key) {
            $this->assertStringContainsString($call, __("chat.$key", [], $locale), "$locale $key");
        }
    }

    #[DataProvider('locales')]
    public function test_full_flow_in_each_locale(string $locale, string $pick): void
    {
        $this->send(['text' => '/start']);
        $this->send(['text' => $pick]);
        $this->assertSame(__('chat.tg_guest_welcome', [], $locale), $this->last()['text']);
        $this->assertSame(__('chat.tg_register', [], $locale), $this->buttons()[0]);

        $this->send(['text' => 'Which barcode scanners do you sell?']);
        $this->assertStringContainsString(__('chat.tg_guest_invite', [], $locale), $this->last()['text']);

        // Typed without the emoji.
        $this->send(['text' => TelegramBot::withoutEmoji(__('chat.tg_register', [], $locale))]);
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);
        $this->send(['text' => TelegramBot::withoutEmoji(__('chat.tg_not_now', [], $locale))]);
        $this->assertSame(TelegramUser::STEP_GUEST, TelegramUser::sole()->step);
        $this->assertSame(__('chat.tg_registration_later', [], $locale), $this->last()['text']);

        $this->send(['text' => __('chat.tg_register', [], $locale)]);
        $this->send(['text' => 'Aziz Karimov']);
        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);
        $this->assertSame(__('chat.tg_registered', [], $locale), $this->last()['text']);
        $this->assertNotContains(__('chat.tg_register', [], $locale), $this->buttons());

        $this->send(['text' => __('chat.tg_register', [], $locale)]);
        $this->assertSame(__('chat.tg_already_registered', ['name' => 'Aziz Karimov'], $locale), $this->last()['text']);

        $this->send(['text' => 'Which barcode scanners do you sell?']);
        $this->assertSame($this->aiReply, $this->last()['text']);
    }

    public function test_language_switch_for_a_guest_who_gave_a_name_then_said_not_now_stays_guest(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_GUEST]);
        $this->send(['text' => '📝 Регистрация']);
        $this->send(['text' => 'Aziz']);
        $this->send(['text' => '⏭ Не сейчас']);
        $this->assertSame(TelegramUser::STEP_GUEST, TelegramUser::sole()->step);

        $this->send(['text' => '/language']);
        $this->send(['text' => '🇬🇧 English']);

        $this->assertSame(TelegramUser::STEP_GUEST, TelegramUser::sole()->step, 'guest pulled back into registration');
    }

    public static function states(): array
    {
        return [
            'language' => [TelegramUser::STEP_LANGUAGE, null, 'language'],
            'guest' => [TelegramUser::STEP_GUEST, null, 'chat.tg_guest_welcome'],
            'name' => [TelegramUser::STEP_NAME, null, 'chat.tg_ask_name'],
            'phone' => [TelegramUser::STEP_PHONE, 'Aziz', 'chat.tg_ask_phone'],
        ];
    }

    #[DataProvider('states')]
    public function test_start_in_each_state(string $step, ?string $name, string $expect): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => $step, 'name' => $name]);

        $this->send(['text' => '/start']);

        $this->assertSame($step, TelegramUser::sole()->step);
        if ($expect === 'language') {
            $this->assertCount(3, $this->buttons());
        } else {
            $this->assertSame(__($expect, ['name' => (string) $name], 'ru'), $this->last()['text']);
        }
        if (in_array($step, [TelegramUser::STEP_NAME, TelegramUser::STEP_PHONE], true)) {
            $this->assertContains(__('chat.tg_not_now', [], 'ru'), $this->buttons(), 'stuck customers can leave');
        }
        $this->assertFalse((bool) ChatConversation::sole()->awaiting_reply, '/start is not a question');
    }

    public function test_start_for_a_registered_customer(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901112233', 'registered_at' => now()]);

        $this->send(['text' => '/start']);

        $this->assertSame(__('chat.tg_welcome', [], 'en'), $this->last()['text']);
        $this->assertNotContains(__('chat.tg_register', [], 'en'), $this->buttons());
    }

    public function test_existing_customer_stuck_at_phone_can_say_not_now_and_ask(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'uz', 'step' => TelegramUser::STEP_PHONE, 'name' => 'Aziz']);

        $this->send(['text' => 'Hozir emas']);
        $this->assertSame(TelegramUser::STEP_GUEST, TelegramUser::sole()->step);

        $this->send(['text' => 'Qanday skanerlar sotasizlar?']);
        $this->assertStringStartsWith($this->aiReply, $this->last()['text']);
    }

    public function test_guest_taps_are_not_waiting_for_reply(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_GUEST]);

        $this->send(['text' => '📝 Регистрация']);

        $this->assertFalse((bool) ChatConversation::sole()->awaiting_reply);
        $this->assertSame([], ChatConversation::sole()->textMessages());
    }

    public function test_long_registered_reply_is_cut_at_a_sentence_end(): void
    {
        $sentence = 'Zebra DS2208 scanners read 1D and 2D codes from screens and paper labels quickly. ';
        $this->aiReply = trim(str_repeat($sentence, 20));
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901112233', 'registered_at' => now()]);

        $this->send(['text' => 'Tell me about your scanners please']);

        $text = $this->last()['text'];
        $this->assertLessThanOrEqual(ChatAssistant::FULL_MAX, mb_strlen($text));
        $this->assertStringEndsWith('labels quickly.', $text);
    }

    public function test_long_guest_reply_is_brief(): void
    {
        $this->aiReply = trim(str_repeat('Zebra scanners read barcodes from screens and paper labels. ', 20));
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_GUEST]);

        $this->send(['text' => 'Tell me about your scanners please']);

        $answer = explode("\n\n", $this->last()['text'])[0];
        $this->assertLessThanOrEqual(ChatAssistant::BRIEF_MAX, mb_strlen($answer));
    }

    public function test_five_word_name_is_refused_and_cyrillic_uzbek_names_accepted(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'uz', 'step' => TelegramUser::STEP_NAME]);

        $this->send(['text' => 'Menga printer kerak edi bugun']);
        $this->assertNull(TelegramUser::sole()->name);
        // Replies on: the five-word text was answered as a question, registration left for later.
        $this->assertSame(TelegramUser::STEP_GUEST, TelegramUser::sole()->step);
        $this->send(['text' => __('chat.tg_register', [], 'uz')]);

        $this->send(['text' => 'Gʻayrat Oʻktamov']);
        $this->assertSame('Gʻayrat Oʻktamov', TelegramUser::sole()->name);
    }

    public function test_repeated_call_request_after_guest_registration_does_not_duplicate_lead(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_GUEST]);
        $this->send(['text' => 'Хочу купить сканеры для магазина']);
        $this->send(['text' => '📞 Заказать звонок']);
        $this->send(['text' => 'Азиз']);
        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);
        $this->send(['text' => '📞 Заказать звонок']);

        $this->assertSame(1, ChatLead::count());
        $this->assertTrue(TelegramUser::sole()->isRegistered());
        $this->assertSame(__('chat.tg_thanks', [], 'ru'), $this->last()['text']);
    }

    public function test_someone_elses_contact_does_not_register_a_guest(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_PHONE, 'name' => 'Aziz']);

        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 777]]);

        $this->assertFalse(TelegramUser::sole()->isRegistered());
        $this->assertContains(__('chat.tg_not_now', [], 'ru'), $this->buttons());
    }

    public function test_record_only_guest_with_ask_registration(): void
    {
        config(['services.telegram.replies' => false]);
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_GUEST, 'ask_registration' => true]);

        $this->send(['text' => 'Здравствуйте, нужен сканер']);

        $this->assertCount(3, $this->buttons(), 'language picker first, as in the old record-only flow');
        $this->send(['text' => '🇷🇺 Русский']);
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);
        $this->assertSame([], $this->buttons(), 'no Not now in record-only mode');
    }
}
