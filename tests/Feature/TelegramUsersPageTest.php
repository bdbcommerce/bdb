<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramUsersPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function user(array $attributes = []): TelegramUser
    {
        return TelegramUser::create($attributes + [
            'telegram_id' => '555', 'username' => 'aziz_uz', 'locale' => 'uz', 'step' => TelegramUser::STEP_DONE,
            'name' => 'Aziz Karimov', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);
    }

    private function conversation(string $externalId, string $channel, array $messages, array $extra = []): ChatConversation
    {
        return ChatConversation::create($extra + [
            'uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => $externalId, 'locale' => 'uz',
            'messages' => $messages, 'message_count' => count($messages),
        ]);
    }

    public function test_menu_and_list_with_search_and_filters(): void
    {
        $this->user();
        $this->user(['telegram_id' => '777', 'username' => 'dilnoza', 'name' => 'Dilnoza', 'phone' => '+998935550000']);
        TelegramUser::create(['telegram_id' => '888', 'step' => TelegramUser::STEP_NAME]);
        $this->conversation('555', 'telegram', [['role' => 'user', 'content' => 'Hi']]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.telegram-users.index'))
            ->assertOk()
            ->assertSee('Telegram')->assertSee('Users')->assertSee('Bot settings')
            ->assertSee('Aziz Karimov')->assertSee('Dilnoza')->assertSee('No name yet')
            ->assertSee('1 chat');

        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => '@dilno']))
            ->assertOk()->assertSee('Dilnoza')->assertDontSee('Aziz Karimov');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => '90 123']))
            ->assertOk()->assertSee('Aziz Karimov')->assertDontSee('Dilnoza');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['filter' => 'pending']))
            ->assertOk()->assertSee('No name yet')->assertDontSee('Aziz Karimov');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => ['x'], 'filter' => ['y']]))->assertOk();
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => '100%_']))->assertOk()->assertSee('No users match');
    }

    public function test_user_page_shows_info_and_all_conversations(): void
    {
        $user = $this->user();
        $lead = ChatLead::create(['channel' => 'telegram', 'name' => 'Aziz Karimov', 'phone' => '+998901234567', 'locale' => 'uz']);
        $withLead = $this->conversation('555', 'telegram', [
            ['role' => 'user', 'content' => '/start', 'aside' => true],
            ['role' => 'user', 'content' => 'Printer narxi?'],
            ['role' => 'assistant', 'content' => 'Narxlar <b>so‘rov</b> bo‘yicha.', 'answered' => true],
            ['role' => 'user', 'type' => 'photo', 'file_id' => 'AgAC_photo_file', 'caption' => 'Shu model'],
        ], ['chat_lead_id' => $lead->id]);
        $this->conversation('555', 'business', [
            ['role' => 'user', 'sender' => 'Aziz', 'content' => 'Salom, BDB'],
            ['role' => 'owner', 'sender' => 'BDB Sales', 'content' => 'Assalomu alaykum!'],
        ]);
        $this->conversation('999', 'telegram', [['role' => 'user', 'content' => 'Someone else']]);

        $this->actingAs($this->admin())->get(route('admin.telegram-users.show', $user))
            ->assertOk()
            ->assertSee('Aziz Karimov')->assertSee('+998901234567')->assertSee('https://t.me/aziz_uz')->assertSee('ID 555')
            ->assertSee('Conversation history')
            ->assertSee('Printer narxi?')->assertSee('Narxlar &lt;b&gt;so‘rov&lt;/b&gt; bo‘yicha.', false)
            ->assertSee('Bot step — not sent to the AI')
            ->assertSee(route('admin.telegram.media', 'AgAC_photo_file'), false)->assertSee('Shu model')
            ->assertSee('Telegram Business')->assertSee('Salom, BDB')->assertSee('Account owner')
            ->assertSee('Message in Telegram')
            ->assertSee(route('admin.chat-conversations.show', $withLead), false)
            ->assertDontSee('Someone else');
    }

    public function test_user_without_conversations_and_conversation_links_back(): void
    {
        $user = TelegramUser::create(['telegram_id' => '123', 'step' => TelegramUser::STEP_LANGUAGE]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))
            ->assertOk()->assertSee('No messages stored')->assertSee('Registration not finished')->assertDontSee('Message in Telegram');

        $registered = $this->user();
        $conversation = $this->conversation('555', 'telegram', [['role' => 'user', 'content' => 'Hi']]);
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $conversation))
            ->assertOk()->assertSee(route('admin.telegram-users.show', $registered), false);
    }

    public function test_pages_are_admin_only(): void
    {
        $user = $this->user();

        $this->get(route('admin.telegram-users.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.telegram-users.show', $user))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.telegram-users.index'))->assertRedirect(route('admin.login'));
    }
}
