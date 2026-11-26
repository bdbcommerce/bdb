<?php

namespace Tests\Feature;

use App\Models\ChatLead;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A call request becomes a lead only with a phone shared by Telegram's contact button (or set by an admin). */
class TelegramVerifiedPhoneTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-100123',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
    }

    private function send(array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'message_id' => $this->updateId,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz'],
            'date' => time(),
        ] + $message], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function last(): Request
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '555')->last()[0];
    }

    /** Registered in a Premium-account chat with a typed phone. */
    private function businessCustomer(): TelegramUser
    {
        return TelegramUser::create([
            'telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'source' => 'business',
            'name' => 'Aziz', 'phone' => '+998900000000', 'registered_at' => now(),
        ]);
    }

    public function test_a_typed_phone_must_be_confirmed_with_the_contact_button_before_a_lead(): void
    {
        $this->businessCustomer();

        $this->send(['text' => '📞 Заказать звонок']);

        $this->assertSame(0, ChatLead::count());
        $this->assertSame(__('chat.tg_call_confirm_phone', [], 'ru'), $this->last()['text']);
        $this->assertTrue($this->last()['reply_markup']['keyboard'][0][0]['request_contact']);
        $this->assertSame(TelegramUser::STEP_PHONE, TelegramUser::sole()->step);

        // Someone else's contact is refused.
        $this->send(['contact' => ['phone_number' => '998901111111', 'user_id' => 999]]);
        $this->assertSame(0, ChatLead::count());

        $this->send(['contact' => ['phone_number' => '998907777777', 'user_id' => 555]]);

        $user = TelegramUser::sole();
        $this->assertSame(['+998907777777', TelegramUser::STEP_DONE], [$user->phone, $user->step]);
        $this->assertTrue($user->hasVerifiedPhone());
        $this->assertSame('+998907777777', ChatLead::sole()->phone, 'the lead has the shared phone, not the typed one');
        $this->assertSame(__('chat.tg_thanks', [], 'ru'), $this->last()['text'], 'already registered: no "you are registered"');
    }

    public function test_not_now_keeps_the_customer_registered_without_a_lead(): void
    {
        $this->businessCustomer();
        $this->send(['text' => '📞 Заказать звонок']);

        $this->send(['text' => '⏭ Не сейчас']);

        $this->assertSame(__('chat.tg_call_later', [], 'ru'), $this->last()['text']);
        $this->assertSame(TelegramUser::STEP_DONE, TelegramUser::sole()->step);
        $this->assertSame(0, ChatLead::count());
    }

    public function test_a_contact_shared_in_the_bot_is_verified_and_a_typed_business_phone_is_not(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_PHONE, 'name' => 'Aziz']);
        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);
        $this->assertTrue(TelegramUser::sole()->hasVerifiedPhone());

        // The same number typed later in a Premium-account chat keeps the verification; another number loses it.
        $user = TelegramUser::sole();
        $user->update(['phone' => '+998905555555', 'phone_verified_at' => null]);
        $this->assertFalse($user->fresh()->hasVerifiedPhone());
    }

    public function test_a_phone_entered_by_an_admin_is_verified(): void
    {
        $user = $this->businessCustomer();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('admin.telegram-users.update', $user), ['name' => 'Aziz', 'phone' => '+998903334455'])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->hasVerifiedPhone());

        $this->send(['text' => '📞 Заказать звонок']);
        $this->assertSame('+998903334455', ChatLead::sole()->phone);
    }
}
