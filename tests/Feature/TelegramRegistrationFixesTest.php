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

/** Regression edges around /start, /language, names, raw webhook input, call requests and shorten(). */
class TelegramRegistrationFixesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 100;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-100999',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'reply' => 'Javob.', 'suggestions' => [], 'offer_callback' => true, 'answered' => true,
                ])]]]]],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function send(array $message): void
    {
        $this->postJson('/telegram/webhook', [
            'update_id' => $this->updateId++,
            'message' => [
                'message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Aziz'], 'date' => time(),
            ] + $message,
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function lastText(): ?string
    {
        return collect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '555'))
            ->map(fn ($pair) => $pair[0]['text'])->last();
    }

    public function test_language_switch_at_the_name_step_asks_for_the_name_in_the_new_language(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_NAME]);

        $this->send(['text' => '/language']);
        $this->send(['text' => 'Oʻzbekcha']);

        $this->assertSame(__('chat.tg_ask_name', [], 'uz'), $this->lastText());
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);
        $this->assertSame('uz', TelegramUser::sole()->locale);
    }

    public function test_language_switch_for_a_registered_customer_does_not_ask_for_the_phone_again(): void
    {
        TelegramUser::create([
            'telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE,
            'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);

        $this->send(['text' => '/language']);
        $this->send(['text' => 'English']);

        $this->assertSame(__('chat.tg_language_changed', [], 'en'), $this->lastText());
        $this->assertSame(TelegramUser::STEP_DONE, TelegramUser::sole()->step);
    }

    public function test_start_for_a_brand_new_customer_and_with_a_deep_link_payload_shows_the_language_picker(): void
    {
        $this->send(['text' => '/start']);
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastText());

        $this->send(['text' => '/start promo_2026']);
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastText());
        $this->assertSame(TelegramUser::STEP_LANGUAGE, TelegramUser::sole()->step);
    }

    public function test_untrimmed_webhook_text_still_matches_language_buttons_and_names(): void
    {
        $this->send(['text' => "  Русский \n"]);
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);

        $this->send(['text' => '   ']);
        $this->assertSame(__('chat.tg_name_invalid', [], 'ru'), $this->lastText());

        $this->send(['text' => " G`ulnora  O‘rinova\t"]);
        $this->assertSame('G`ulnora O‘rinova', TelegramUser::sole()->name);
    }

    public function test_names_starting_with_an_apostrophe_or_over_60_chars_are_rejected(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_NAME]);

        foreach (['‘Ali', '`Ali', str_repeat('a', 61)] as $name) {
            $this->send(['text' => $name]);
            $this->assertSame(__('chat.tg_name_invalid', [], 'en'), $this->lastText(), $name);
        }
        $this->assertNull(TelegramUser::sole()->name);
    }

    public function test_call_request_after_a_staff_reply_keeps_it_in_the_lead_transcript(): void
    {
        TelegramUser::create([
            'telegram_id' => '555', 'locale' => 'uz', 'step' => TelegramUser::STEP_DONE,
            'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);

        $this->send(['text' => 'Narxi?']);
        ChatConversation::sole()->appendAdminMessage('1 200 000 soʻm', 'Sardor');
        $this->send(['text' => __('chat.tg_request_call', [], 'uz')]);

        $lead = ChatLead::sole();
        $this->assertContains(['role' => 'admin', 'content' => '1 200 000 soʻm'], $lead->transcript);
        $this->assertSame('Narxi?', $lead->topic);
    }

    public function test_call_request_after_the_lead_was_deleted_creates_a_new_one(): void
    {
        TelegramUser::create([
            'telegram_id' => '555', 'locale' => 'uz', 'step' => TelegramUser::STEP_DONE,
            'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);

        $this->send(['text' => 'Narxi?']);
        $this->send(['text' => __('chat.tg_request_call', [], 'uz')]);
        ChatLead::sole()->delete();
        $this->send(['text' => __('chat.tg_request_call', [], 'uz')]);

        $this->assertSame(1, ChatLead::count());
    }

    public function test_shorten_edge_limits(): void
    {
        foreach ([1, 2, 3, 10, 50, 300] as $max) {
            foreach ([
                str_repeat('a', 500),
                str_repeat('ab ', 200),
                'https://bdb.uz/'.str_repeat('путь/', 100),
                str_repeat('Ҳа. ', 200),
                str_repeat('😀', 400),
                "Line one\n".str_repeat('x', 400),
            ] as $text) {
                $out = ChatAssistant::shorten($text, $max);
                $this->assertLessThanOrEqual($max, mb_strlen($out), "max {$max}: ".mb_substr($text, 0, 20));
                $this->assertNotSame('', $out);
                $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
            }
        }

        $this->assertSame('Exactly ten', ChatAssistant::shorten('  Exactly ten  ', 11));
    }
}
