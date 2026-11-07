<?php

namespace Tests\Feature;

use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Customers come from chat info; admins add details or ask for registration on the next message. */
class TelegramUserDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => false,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    private function send(array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => $message + [
            'message_id' => $this->updateId,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'username' => 'aziz_uz'],
            'date' => time(),
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function sentTexts(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]['text'])->values()->all();
    }

    public function test_phone_formats_and_partial_update(): void
    {
        $user = TelegramUser::create(['telegram_id' => '555', 'phone' => '+998901112233']);
        $admin = User::factory()->create(['is_admin' => true]);
        $save = fn (array $data) => $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), $data);

        $save(['phone' => '(90) 123-45-67'])->assertSessionHasNoErrors();
        $this->assertSame('+998901234567', $user->fresh()->phone); // local number → +998

        $save(['phone' => '+7 701 123 45 67'])->assertSessionHasNoErrors();
        $this->assertSame('+77011234567', $user->fresh()->phone);

        $save(['phone' => '12345678'])->assertSessionHasErrors('phone'); // no country code, not 9 digits

        // A request without the phone field keeps the stored number.
        $save(['company' => 'BDB'])->assertSessionHasNoErrors();
        $this->assertSame('+77011234567', $user->fresh()->phone);
    }

    public function test_admin_edits_details(): void
    {
        $this->send(['text' => 'Salom']);
        $user = TelegramUser::sole();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), [
            'name' => 'Aziz Karimov', 'phone' => '998 (90) 123-45-67', 'company' => 'Tashkent Trade', 'notes' => 'Wants 1000 labels', 'locale' => 'uz',
        ])->assertRedirect(route('admin.telegram-users.show', $user));

        $user->refresh();
        $this->assertSame(['Aziz Karimov', '+998901234567', 'Tashkent Trade', 'uz'], [$user->name, $user->phone, $user->company, $user->locale]);
        $this->assertFalse($user->isRegistered());

        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))
            ->assertOk()->assertSee('Edit details')->assertSee('Tashkent Trade')->assertSee('Wants 1000 labels');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => 'tashkent']))->assertOk()->assertSee('Aziz Karimov');

        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['phone' => 'call me'])->assertSessionHasErrors('phone');
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['locale' => 'de'])->assertSessionHasErrors('locale');
        Http::assertNothingSent();
    }

    public function test_ask_registration_on_next_message_then_back_to_recording(): void
    {
        $this->send(['text' => 'Salom']);
        $user = TelegramUser::sole();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('admin.telegram-users.update', $user), ['ask_registration' => '1'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'next time'));
        $this->assertTrue($user->fresh()->ask_registration);

        $this->send(['text' => 'Narxlar qanday?']);
        $this->assertSame([TelegramBot::LANGUAGE_PROMPT], $this->sentTexts());

        $this->send(['text' => '🇺🇿 Oʻzbekcha']);
        $this->send(['text' => 'Aziz Karimov']);
        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);

        $user->refresh();
        $this->assertTrue($user->isRegistered());
        $this->assertFalse($user->ask_registration);
        $this->assertSame(['uz', 'Aziz Karimov', '+998901234567'], [$user->locale, $user->name, $user->phone]);
        $this->assertSame(__('chat.tg_registered', [], 'uz'), last($this->sentTexts()));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));

        // Registered: back to recording only.
        $count = count($this->sentTexts());
        $this->send(['text' => 'Rahmat']);
        $this->assertCount($count, $this->sentTexts());
    }

    public function test_admin_entered_name_is_not_asked_again(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'name' => 'Aziz Karimov', 'ask_registration' => true]);

        $this->send(['text' => 'Salom']);
        $this->send(['text' => 'Русский']);

        $this->assertSame(TelegramUser::STEP_PHONE, TelegramUser::sole()->step);
        $this->assertStringContainsString('Aziz Karimov', last($this->sentTexts()));
    }

    public function test_premium_account_customers_are_listed_as_users(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => '777', 'name' => 'BDB Sales']);
        $this->postJson('/telegram/webhook', ['update_id' => 900, 'business_message' => [
            'business_connection_id' => 'conn-1', 'message_id' => 1,
            'chat' => ['id' => 4242, 'type' => 'private', 'first_name' => 'Bobur'],
            'from' => ['id' => 4242, 'first_name' => 'Bobur', 'last_name' => 'T.', 'username' => 'bobur_t'],
            'date' => time(), 'text' => 'Salom',
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        // The owner's own messages don't create a user.
        $this->postJson('/telegram/webhook', ['update_id' => 901, 'business_message' => [
            'business_connection_id' => 'conn-1', 'message_id' => 2,
            'chat' => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 777, 'first_name' => 'BDB'],
            'date' => time(), 'text' => 'Assalomu alaykum',
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $user = TelegramUser::sole();
        $this->assertSame(['4242', 'business', 'Bobur T.', 'bobur_t'], [$user->telegram_id, $user->source, $user->profile_name, $user->username]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.telegram-users.show', $user))
            ->assertOk()->assertSee('Bobur T.')->assertSee('Premium account')->assertSee('Salom');
    }
}
