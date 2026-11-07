<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Edge cases of "ask for registration info" in Premium-account (Telegram Business) chats. */
class BusinessRegistrationAskEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private const OWNER = 777;

    private int $updateId = 1;

    private int $msgId = 100;

    private int $sentId = 5000;

    /** Makes Telegram refuse sends (a later Http::fake would not override this one). */
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
            : Http::response(['ok' => true, 'result' => ['message_id' => $this->sentId++]]));
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => (string) self::OWNER, 'name' => 'BDB Sales', 'can_reply' => true]);
    }

    private function hook(array $update): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++] + $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** A customer message in the Premium-account chat; returns its message_id. */
    private function customer(array $message, bool $edited = false, string $lang = 'ru'): int
    {
        $id = $message['message_id'] ?? ++$this->msgId;
        $this->hook([($edited ? 'edited_business_message' : 'business_message') => $message + [
            'business_connection_id' => 'conn-1',
            'message_id' => $id,
            'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'language_code' => $lang],
            'date' => time(),
        ] + ($edited ? ['edit_date' => time()] : [])]);

        return $id;
    }

    private function owner(string $text): void
    {
        $this->hook(['business_message' => [
            'business_connection_id' => 'conn-1', 'message_id' => ++$this->msgId,
            'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz'],
            'from' => ['id' => self::OWNER, 'first_name' => 'BDB'], 'date' => time(), 'text' => $text,
        ]]);
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

    /** @return list<Request> */
    private function sent(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($p) => $p[0])->values()->all();
    }

    private function lastText(): ?string
    {
        return last($this->sent())['text'] ?? null;
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function setAsk(bool $ask): TelegramUser
    {
        $user = TelegramUser::sole();
        $this->actingAs($this->admin())
            ->put(route('admin.telegram-users.update', $user), $ask ? ['ask_registration' => '1'] : [])->assertSessionHasNoErrors();

        return $user->fresh();
    }

    private function business(): ChatConversation
    {
        return ChatConversation::where('channel', 'business')->sole();
    }

    private function assertNoAi(): void
    {
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent') || str_contains($r->url(), 'chat/completions'));
    }

    public function test_no_messages_when_not_asked_even_with_a_leftover_step(): void
    {
        $this->customer(['text' => 'Salom']);
        TelegramUser::sole()->update(['step' => TelegramUser::STEP_NAME]);
        $this->customer(['text' => 'Aziz']);
        $this->customer(['photo' => [['file_id' => 'p1']]]);
        $this->assertSame([], $this->sent());
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);
        $this->assertTrue($this->business()->awaiting_reply);
    }

    public function test_waiting_list_state_at_each_step(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->actingAs($this->admin())->post(route('admin.chat-conversations.handled', $this->business()))->assertRedirect();
        $this->assertFalse($this->business()->fresh()->awaiting_reply);

        $this->customer(['text' => 'Skaner narxi qancha?']); // real question → asks name
        $this->assertSame(__('chat.tg_biz_ask_name', [], 'ru'), $this->lastText());
        $this->assertTrue($this->business()->awaiting_reply);

        $this->customer(['text' => 'Aziz Karimov']); // name answer: aside
        $this->assertTrue($this->business()->awaiting_reply);
        $entry = collect($this->business()->messages)->firstWhere('content', 'Aziz Karimov');
        $this->assertTrue($entry['aside'] ?? false);

        $this->customer(['text' => '+998 (90) 123-45-67']);
        $this->assertSame('+998901234567', TelegramUser::sole()->phone);
        $this->assertTrue($this->business()->awaiting_reply); // the question is still unanswered

        $this->owner('Narxi 100$');
        $this->assertFalse($this->business()->awaiting_reply);
        $this->assertNoAi();
    }

    public function test_owner_writing_mid_flow_does_not_trigger_the_bot(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->customer(['text' => 'Price?']);
        $count = count($this->sent());

        $this->owner('Hello! What is your name?');
        $this->assertCount($count, $this->sent());
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step);
        $this->assertFalse($this->business()->awaiting_reply);

        $this->customer(['text' => 'Aziz']);
        $this->assertSame(__('chat.tg_biz_ask_phone', ['name' => 'Aziz'], 'ru'), $this->lastText());
        // The name answer is a bot step, not a new question after the owner's reply.
        $this->assertFalse($this->business()->awaiting_reply);
    }

    public function test_media_and_foreign_contact_at_phone_step(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->customer(['text' => 'Hi']);
        $this->customer(['text' => 'Oʻktam Toʻxtayev']);
        $this->assertSame('Oʻktam Toʻxtayev', TelegramUser::sole()->name);

        $this->customer(['photo' => [['file_id' => 'small'], ['file_id' => 'big']]]);
        $this->assertSame(__('chat.tg_biz_phone_invalid', [], 'ru'), $this->lastText());
        $this->customer(['contact' => ['phone_number' => '998901111111', 'user_id' => 999, 'first_name' => 'Other']]);
        $this->assertSame(__('chat.tg_biz_phone_invalid', [], 'ru'), $this->lastText());
        $this->assertSame(TelegramUser::STEP_PHONE, TelegramUser::sole()->step);

        $this->customer(['contact' => ['phone_number' => '998902222222', 'user_id' => 555, 'first_name' => 'Aziz']]);
        $user = TelegramUser::sole();
        $this->assertSame(['+998902222222', TelegramUser::STEP_DONE], [$user->phone, $user->step]);
        $this->assertSame('big', collect($this->business()->messages)->firstWhere('type', 'photo')['file_id']);
        $this->assertNoAi();
    }

    public function test_edits_during_the_flow_send_nothing_and_keep_bot_steps_aside(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->actingAs($this->admin())->post(route('admin.chat-conversations.handled', $this->business()));
        $this->customer(['text' => 'Hi']);
        $this->owner('Hello');
        $nameId = $this->customer(['text' => 'Azz']);
        $count = count($this->sent());

        $this->customer(['message_id' => $nameId, 'text' => 'Aziz'], edited: true);
        $this->assertCount($count, $this->sent());
        $entry = collect($this->business()->messages)->firstWhere('message_id', $nameId);
        $this->assertSame('Aziz', $entry['content']);
        $this->assertFalse($this->business()->awaiting_reply, 'Editing a name answer put the chat back on the waiting list');
        $this->assertTrue($entry['aside'] ?? false, 'Edited registration answer lost its "aside" flag');
    }

    public function test_connection_disabled_sends_nothing(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        TelegramBusinessConnection::query()->update(['is_enabled' => false]);
        $this->customer(['text' => 'Hello']);
        $this->assertSame([], $this->sent());
        $this->assertTrue(TelegramUser::sole()->ask_registration);
    }

    public function test_flag_unticked_mid_flow_stops_asking(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->customer(['text' => 'Hi']);
        $count = count($this->sent());
        $this->setAsk(false);

        $this->customer(['text' => 'Aziz']);
        $this->assertCount($count, $this->sent());
        $entry = collect($this->business()->messages)->firstWhere('content', 'Aziz');
        $this->assertArrayNotHasKey('aside', $entry);
        $this->assertNull(TelegramUser::sole()->name);
    }

    public function test_reticking_after_an_abandoned_flow_restarts_with_a_question(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->customer(['text' => 'Hi']); // step → name
        $this->setAsk(false);
        $this->setAsk(true); // days later

        $this->customer(['text' => 'Narxlar']); // a real question, not a name
        $this->assertNull(TelegramUser::sole()->name, 'Leftover step: the first message after re-ticking was saved as the name');
    }

    public function test_shared_state_with_the_bot_chat(): void
    {
        // Customer known from the Premium chat, admin asks, customer opens the bot chat first.
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->botChat(['text' => '/start']);
        $this->assertSame(TelegramBot::LANGUAGE_PROMPT, $this->lastText());

        // Then continues in the Premium chat: name asked there.
        $this->customer(['text' => 'Hello']);
        $this->assertSame(__('chat.tg_biz_ask_name', [], 'ru'), $this->lastText());
        $this->customer(['text' => 'Aziz']);
        $this->assertSame(TelegramUser::STEP_PHONE, TelegramUser::sole()->step);

        // In the bot chat, the phone is asked with the contact button.
        $this->botChat(['text' => 'hi']);
        $this->assertArrayHasKey('reply_markup', last($this->sent())->data());
        $this->botChat(['contact' => ['phone_number' => '998903333333', 'user_id' => 555, 'first_name' => 'Aziz']]);
        $user = TelegramUser::sole();
        $this->assertSame(['+998903333333', TelegramUser::STEP_DONE, false], [$user->phone, $user->step, $user->ask_registration]);

        // Registered: neither chat asks again.
        $count = count($this->sent());
        $this->customer(['text' => 'Rahmat']);
        $this->botChat(['text' => 'Rahmat']);
        $this->assertCount($count, $this->sent());
        $this->assertNoAi();
    }

    public function test_failed_send_does_not_advance_silently(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->failSends = true;
        $this->customer(['text' => 'Hello']);
        // The name question was never delivered; the next message must not be taken as the name.
        $this->failSends = false;
        $this->customer(['text' => 'Narxlar']);
        $this->assertNull(TelegramUser::sole()->name, 'Question not delivered but the next message was saved as the name');
    }

    public function test_admin_views_render_bot_entries(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->setAsk(true);
        $this->customer(['text' => 'Price <b>?</b>']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $this->business()))
            ->assertOk()->assertSee('BDB bot')->assertSee(__('chat.tg_biz_ask_name', [], 'ru'))
            ->assertDontSee('Price <b>?</b>', false);
        $this->actingAs($admin)->get(route('admin.chat-conversations.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.telegram-users.show', TelegramUser::sole()))->assertOk()->assertDontSee('BOT');

        // Waiting list: the preview is the customer's question, not the bot's prompt.
        $this->actingAs($admin)->get(route('admin.awaiting-replies.index'))->assertOk()
            ->assertSee('2 messages without answer') // "Salom" and "Price" are both unanswered
            ->assertDontSee(__('chat.tg_biz_ask_name', [], 'ru'));
    }

    public function test_translations_exist_in_all_locales(): void
    {
        foreach (['uz', 'ru', 'en'] as $locale) {
            foreach (['tg_biz_ask_name', 'tg_biz_ask_phone', 'tg_biz_phone_invalid', 'tg_biz_registered', 'tg_name_invalid'] as $key) {
                $this->assertNotSame('chat.'.$key, __('chat.'.$key, ['name' => 'X'], $locale), "$locale: $key");
            }
            $this->assertStringContainsString('X', __('chat.tg_biz_ask_phone', ['name' => 'X'], $locale));
        }
    }

    public function test_language_from_telegram_app_and_fallback(): void
    {
        $this->customer(['text' => 'Salom'], lang: 'uz');
        $this->setAsk(true);
        $this->customer(['text' => 'Hi'], lang: 'uz');
        $this->assertSame(__('chat.tg_biz_ask_name', [], 'uz'), $this->lastText());
        $this->assertSame('uz', TelegramUser::sole()->locale);
    }

    public function test_phone_normalisation(): void
    {
        $this->assertSame('+998901234567', TelegramUser::normalizePhone('90 123 45 67'));
        $this->assertSame('+998901234567', TelegramUser::normalizePhone('+998 (90) 123-45-67'));
        $this->assertSame('+79161234567', TelegramUser::normalizePhone('+7 916 123 45 67'));
        $this->assertNull(TelegramUser::normalizePhone('12345'));
        $this->assertNull(TelegramUser::normalizePhone(''));
        $this->assertNull(TelegramUser::normalizePhone('call me'));
        $this->assertNull(TelegramUser::normalizePhone(str_repeat('9', 20)));
    }
}
