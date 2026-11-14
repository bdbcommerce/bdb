<?php

namespace Tests\Feature;

use App\Models\ChatLead;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: a phone typed in a Premium-account chat is not verified unless it is the already-verified number. */
class BusinessTypedPhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    private int $sentId = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-100123',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => false,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => $this->sentId++]]));
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => '777', 'name' => 'BDB Sales', 'can_reply' => true]);
    }

    private function customer(string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'business_message' => [
            'business_connection_id' => 'conn-1', 'message_id' => $this->updateId,
            'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'language_code' => 'en'],
            'date' => time(), 'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function bot(string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'message_id' => $this->updateId, 'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz'], 'date' => time(), 'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function askWithPhone(?string $phone): TelegramUser
    {
        $this->customer('Hello');
        $user = TelegramUser::sole();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('admin.telegram-users.update', $user), ['name' => 'Aziz', 'phone' => $phone, 'ask_registration' => '1'])
            ->assertSessionHasNoErrors();

        return $user->fresh();
    }

    public function test_same_number_typed_keeps_the_admin_verification(): void
    {
        $this->assertTrue($this->askWithPhone('+998901234567')->hasVerifiedPhone());
        $this->customer('Hi');            // asks the phone (name known)
        $this->customer('90 123 45 67');  // same number, other format

        $user = TelegramUser::sole();
        $this->assertTrue($user->isRegistered());
        $this->assertSame('+998901234567', $user->phone);
        $this->assertTrue($user->hasVerifiedPhone());
    }

    public function test_other_number_typed_is_not_verified_and_call_needs_confirmation(): void
    {
        $this->askWithPhone('+998901234567');
        $this->customer('Hi');
        $this->customer('+998 93 000 00 00');

        $user = TelegramUser::sole();
        $this->assertSame('+998930000000', $user->phone);
        $this->assertFalse($user->hasVerifiedPhone());

        config(['services.telegram.replies' => true]);
        $this->bot('📞 Request a call');
        $this->assertSame(0, ChatLead::count());
        $last = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && ! isset($r['business_connection_id']))->last()[0];
        $this->assertSame(__('chat.tg_call_confirm_phone', [], 'en'), $last['text']);
    }

    public function test_typed_phone_without_prior_phone_is_not_verified(): void
    {
        $this->askWithPhone(null);
        $this->customer('Hi');
        $this->customer('Aziz');
        $this->customer('901234567');
        $user = TelegramUser::sole();
        $this->assertTrue($user->isRegistered());
        $this->assertFalse($user->hasVerifiedPhone());
    }
}
