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

/** QA edge cases for admin-entered customer details and "ask registration" in record-only mode. */
class TelegramUserDetailsEdgeCasesTest extends TestCase
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

    private function sent(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())->values()->all();
    }

    private function sentTexts(): array
    {
        return array_column($this->sent(), 'text');
    }

    private function asking(): TelegramUser
    {
        return TelegramUser::create(['telegram_id' => '555', 'ask_registration' => true]);
    }

    public function test_nothing_is_sent_when_not_asking(): void
    {
        foreach (['Salom', '/start', '/language', 'Русский'] as $text) {
            $this->send(['text' => $text]);
        }
        $this->send(['photo' => [['file_id' => 'p1', 'file_size' => 10]]]);
        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);

        Http::assertNothingSent();
        $user = TelegramUser::sole();
        $this->assertFalse($user->isRegistered());
        $this->assertSame(TelegramUser::STEP_LANGUAGE, $user->step);
        $this->assertSame('bot', $user->source);
    }

    public function test_ask_flow_with_start_language_media_and_foreign_contact(): void
    {
        $this->asking();

        $this->send(['text' => '/start']);
        $this->assertSame([TelegramBot::LANGUAGE_PROMPT], $this->sentTexts());

        $this->send(['text' => 'Русский']);
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);

        // /start mid-flow repeats the current step.
        $this->send(['text' => '/start']);
        $this->assertSame(__('chat.tg_ask_name', [], 'ru'), last($this->sentTexts()));

        // Media at the name step is not a name.
        $this->send(['photo' => [['file_id' => 'p1', 'file_size' => 10]]]);
        $this->assertSame(__('chat.tg_name_invalid', [], 'ru'), last($this->sentTexts()));

        $this->send(['text' => 'Азиз Каримов']);
        $this->assertSame(TelegramUser::STEP_PHONE, TelegramUser::sole()->step);

        // Someone else's contact is refused.
        $this->send(['contact' => ['phone_number' => '998911111111', 'user_id' => 999]]);
        $this->assertSame(__('chat.tg_phone_not_own', [], 'ru'), last($this->sentTexts()));
        // Contact without user_id is refused.
        $this->send(['contact' => ['phone_number' => '998911111111']]);
        $this->assertSame(__('chat.tg_phone_not_own', [], 'ru'), last($this->sentTexts()));
        $this->assertFalse(TelegramUser::sole()->isRegistered());
        $this->assertTrue(TelegramUser::sole()->ask_registration);

        // /language mid-flow: picks again, the typed name is kept, back to phone.
        $this->send(['text' => '/language']);
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, last($this->sentTexts()));
        $this->send(['text' => '🇬🇧 English']);
        $this->assertSame(['en', TelegramUser::STEP_PHONE, 'Азиз Каримов'], [TelegramUser::sole()->locale, TelegramUser::sole()->step, TelegramUser::sole()->name]);

        $this->send(['contact' => ['phone_number' => '+998901234567', 'user_id' => 555]]);
        $user = TelegramUser::sole();
        $this->assertTrue($user->isRegistered());
        $this->assertFalse($user->ask_registration);
        $this->assertSame('+998901234567', $user->phone);

        $last = last($this->sent());
        $this->assertSame(__('chat.tg_registered', [], 'en'), $last['text']);
        $this->assertSame(['remove_keyboard' => true], json_decode(is_string($last['reply_markup']) ? $last['reply_markup'] : json_encode($last['reply_markup']), true));

        // Afterwards: recording only, even for commands and media.
        $count = count($this->sent());
        $this->send(['text' => '/start']);
        $this->send(['text' => '/language']);
        $this->send(['voice' => ['file_id' => 'v1', 'duration' => 2]]);
        $this->assertCount($count, $this->sent());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));
    }

    public function test_replies_mode_is_unaffected(): void
    {
        config(['services.telegram.replies' => true]);

        $this->send(['text' => 'Salom']);
        $this->assertSame([TelegramBot::LANGUAGE_PROMPT], $this->sentTexts());
        $this->send(['text' => 'English']);
        $this->send(['text' => 'Aziz']);
        $this->send(['contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);

        $last = last($this->sent());
        $markup = is_string($last['reply_markup']) ? json_decode($last['reply_markup'], true) : $last['reply_markup'];
        $this->assertArrayHasKey('keyboard', $markup);
        $this->assertFalse(TelegramUser::sole()->ask_registration);
    }

    public function test_update_validation_and_phone_formats(): void
    {
        $user = TelegramUser::create(['telegram_id' => '555']);
        $admin = User::factory()->create(['is_admin' => true]);
        $put = fn (array $data) => $this->actingAs($admin)->from(route('admin.telegram-users.show', $user))
            ->put(route('admin.telegram-users.update', $user), $data + ['name' => null, 'phone' => null, 'company' => null, 'notes' => null, 'locale' => null]);

        foreach (['+998 90 123 45 67' => '+998901234567', '998901234567' => '+998901234567', '(90) 123-45-67' => '+998901234567', '+1234567' => '+1234567'] as $in => $out) {
            $put(['phone' => $in])->assertSessionHasNoErrors();
            $this->assertSame($out, $user->fresh()->phone, $in);
        }
        foreach (['123456', '+1234567890123456', '++998901234567', '+998.90.123', 'abc1234567', '+998 90 123 45 67 ext 2'] as $bad) {
            $put(['phone' => $bad])->assertSessionHasErrors('phone');
        }

        $put(['notes' => str_repeat('я', 2000), 'name' => str_repeat('Ш', 60), 'company' => str_repeat('O‘', 60)])->assertSessionHasNoErrors();
        $this->assertSame(2000, mb_strlen($user->fresh()->notes));
        $put(['notes' => str_repeat('a', 2001)])->assertSessionHasErrors('notes');
        $put(['name' => str_repeat('a', 61)])->assertSessionHasErrors('name');
        $put(['company' => str_repeat('a', 121)])->assertSessionHasErrors('company');
        $put(['ask_registration' => 'yes'])->assertSessionHasErrors('ask_registration');

        // Old input is kept after a validation error.
        $this->actingAs($admin)->from(route('admin.telegram-users.show', $user))
            ->put(route('admin.telegram-users.update', $user), ['name' => 'Kept Name', 'phone' => 'bad'])
            ->assertRedirect(route('admin.telegram-users.show', $user));
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertSee('value="Kept Name"', false)->assertSee('Enter the phone with the country code');

        // Empty values clear the fields.
        $put([])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->phone);
        $this->assertNull($user->fresh()->notes);
    }

    public function test_ask_registration_can_be_unticked_and_is_ignored_for_registered(): void
    {
        $user = TelegramUser::create(['telegram_id' => '555', 'ask_registration' => true]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['ask_registration' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->ask_registration);

        $user->update(['registered_at' => now(), 'step' => TelegramUser::STEP_DONE]);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['ask_registration' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->ask_registration);
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()->assertDontSee('Ask for registration info');
    }

    public function test_xss_is_escaped_on_show_and_index(): void
    {
        $user = TelegramUser::create(['telegram_id' => '555', 'profile_name' => '<img src=x onerror=alert(2)>', 'username' => 'u<b>x</b>']);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), [
            'name' => '<script>alert(1)</script>', 'company' => '"><svg onload=alert(3)>', 'notes' => "</textarea><script>alert(4)</script>\nline2",
        ])->assertSessionHasNoErrors();

        foreach ([route('admin.telegram-users.show', $user), route('admin.telegram-users.index')] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            foreach (['<script>alert(1)</script>', '<svg onload=alert(3)>', '<script>alert(4)</script>', '<img src=x onerror=alert(2)>', '<b>x</b>'] as $raw) {
                $this->assertStringNotContainsString($raw, $html, $url);
            }
        }
    }

    public function test_access_control_on_update(): void
    {
        $user = TelegramUser::create(['telegram_id' => '555']);

        $this->put(route('admin.telegram-users.update', $user), ['name' => 'X'])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->put(route('admin.telegram-users.update', $user), ['name' => 'X'])->assertRedirect(route('admin.login'));
        $this->assertNull($user->fresh()->name);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('admin.telegram-users.update', 99999), ['name' => 'X'])->assertNotFound();
    }

    public function test_business_edits_and_owner_do_not_create_users(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => '777', 'name' => 'BDB Sales']);
        $post = fn (string $key, array $msg) => $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, $key => $msg + [
            'business_connection_id' => 'conn-1', 'message_id' => $this->updateId, 'date' => time(), 'text' => 'Salom',
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $post('edited_business_message', ['chat' => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 4242, 'first_name' => 'B']]);
        $post('business_message', ['chat' => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 777, 'first_name' => 'Owner']]);
        $this->assertSame(0, TelegramUser::count());

        // A bot user who later writes to the Premium account keeps source "bot" and admin details.
        TelegramUser::create(['telegram_id' => '4242', 'name' => 'Admin Name', 'company' => 'ACME']);
        $post('business_message', ['chat' => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 4242, 'first_name' => 'Bobur']]);
        $user = TelegramUser::sole();
        $this->assertSame(['bot', 'Admin Name', 'ACME', 'Bobur'], [$user->source, $user->name, $user->company, $user->profile_name]);
        Http::assertNothingSent();
    }
}
