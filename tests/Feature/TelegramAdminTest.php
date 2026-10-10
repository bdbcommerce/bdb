<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramAdminTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => self::TOKEN]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function conversationWithMedia(): ChatConversation
    {
        TelegramUser::create([
            'telegram_id' => '555', 'username' => 'aziz_uz', 'locale' => 'ru', 'step' => 'done',
            'name' => 'Aziz Karimov', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);

        return ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'page' => 'Telegram bot', 'message_count' => 4,
            'messages' => [
                ['role' => 'user', 'type' => 'photo', 'file_id' => 'AgACAgIAAxkBAAIB_photo'],
                ['role' => 'user', 'content' => 'Что это за сканер?'],
                ['role' => 'assistant', 'content' => 'Это Zebra DS2208.', 'answered' => true],
                ['role' => 'user', 'type' => 'location', 'file_id' => null],
            ],
        ]);
    }

    public function test_settings_page_shows_env_status_without_inputs_or_secrets(): void
    {
        config(['services.telegram.chat_id' => null, 'services.telegram.webhook_secret' => 'a_valid_webhook_secret_1']);

        $html = $this->actingAs($this->admin())->get(route('admin.telegram.edit'))->assertOk()
            ->assertSeeInOrder(['TELEGRAM_BOT_TOKEN', 'Set', 'TELEGRAM_CHAT_ID', 'Not set', 'TELEGRAM_WEBHOOK_SECRET', 'Set'])
            ->assertDontSee(self::TOKEN)
            ->assertDontSee('a_valid_webhook_secret_1')
            ->getContent();

        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertStringNotContainsString('name="bot_token"', $html);
    }

    public function test_connect_requires_a_secret_in_env(): void
    {
        config(['services.telegram.webhook_secret' => null]);
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('admin.telegram.connect'))
            ->assertSessionHas('telegram_error', fn ($m) => str_contains($m, 'TELEGRAM_WEBHOOK_SECRET'));

        Http::assertNothingSent();
    }

    public function test_conversation_shows_customer_and_media_with_load_buttons(): void
    {
        $conversation = $this->conversationWithMedia();

        $this->actingAs($this->admin())
            ->get(route('admin.chat-conversations.show', $conversation))
            ->assertOk()
            ->assertSee('Aziz Karimov')
            ->assertSee('+998901234567')
            ->assertSee('Photo')
            ->assertSee('Load')
            ->assertSee(route('admin.telegram.media', 'AgACAgIAAxkBAAIB_photo'), false)
            ->assertSee('Location')
            ->assertSee('not stored')
            ->assertDontSee(self::TOKEN);

        $this->actingAs($this->admin())
            ->get(route('admin.chat-conversations.index'))
            ->assertOk()
            ->assertSee('[Photo]')
            ->assertSee('1 question')
            ->assertSee('2 files');
    }

    public function test_conversations_can_be_filtered_by_type(): void
    {
        $this->conversationWithMedia();
        ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'web', 'locale' => 'en', 'page' => 'Brands',
            'message_count' => 2, 'unanswered_count' => 1,
            'messages' => [['role' => 'user', 'content' => 'Website question'], ['role' => 'assistant', 'content' => 'Hi', 'answered' => false]],
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.chat-conversations.index'))
            ->assertOk()->assertSee('Website question')->assertSee('[Photo]')
            ->assertSee('Website')->assertSee('Telegram')->assertSee('Aziz Karimov');

        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['channel' => 'web']))
            ->assertOk()->assertSee('Website question')->assertDontSee('[Photo]');

        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['channel' => 'telegram']))
            ->assertOk()->assertSee('[Photo]')->assertDontSee('Website question');

        // Type and status filters combine.
        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['channel' => 'telegram', 'filter' => 'unanswered']))
            ->assertOk()->assertDontSee('[Photo]')->assertDontSee('Website question');

        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['channel' => 'bogus']))
            ->assertOk()->assertSee('Website question')->assertSee('[Photo]');
    }

    public function test_admin_can_message_a_telegram_customer(): void
    {
        $conversation = $this->conversationWithMedia();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $conversation))
            ->assertOk()->assertSee('Message the customer in Telegram');

        $this->actingAs($admin)
            ->post(route('admin.chat-conversations.reply', $conversation), ['message' => '  Здравствуйте! Сканер есть в наличии.  '])
            ->assertRedirect(route('admin.chat-conversations.show', $conversation))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '555'
            && $r['text'] === 'Здравствуйте! Сканер есть в наличии.');

        $last = collect($conversation->fresh()->messages)->last();
        $this->assertSame(['role' => 'admin', 'content' => 'Здравствуйте! Сканер есть в наличии.', 'author' => $admin->name], $last);

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $conversation))
            ->assertSee('BDB team · '.$admin->name, false);
    }

    public function test_failed_delivery_is_reported_and_not_logged(): void
    {
        $conversation = $this->conversationWithMedia();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);

        $this->actingAs($this->admin())
            ->from(route('admin.chat-conversations.show', $conversation))
            ->post(route('admin.chat-conversations.reply', $conversation), ['message' => 'Hello'])
            ->assertRedirect(route('admin.chat-conversations.show', $conversation))
            ->assertSessionHas('telegram_error')
            ->assertSessionHasInput('message', 'Hello');

        $this->assertCount(4, $conversation->fresh()->messages);
    }

    public function test_reply_needs_a_message_and_a_telegram_conversation(): void
    {
        $telegram = $this->conversationWithMedia();
        $web = ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'web', 'locale' => 'en',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]);
        Http::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $telegram), ['message' => '   '])
            ->assertSessionHasErrors('message');
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $web), ['message' => 'Hi'])
            ->assertNotFound();
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $web))
            ->assertOk()->assertDontSee('Message the customer in Telegram');

        auth()->logout();
        $this->post(route('admin.chat-conversations.reply', $telegram), ['message' => 'Hi'])
            ->assertRedirect(route('admin.login'));

        Http::assertNothingSent();
    }

    public function test_media_is_proxied_from_telegram_for_admins(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_7.jpg']]),
            'api.telegram.org/file/bot*/photos/file_7.jpg' => Http::response('JPEGDATA', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.telegram.media', 'AgACAgIAAxkBAAIB_photo'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame('JPEGDATA', $response->getContent());
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString(self::TOKEN, implode(' ', $response->headers->all('location') ?: []));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getFile') && $r['file_id'] === 'AgACAgIAAxkBAAIB_photo');
    }

    public function test_non_media_files_are_downloaded_not_rendered(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/page.html']]),
            'api.telegram.org/file/*' => Http::response('<script>alert(1)</script>', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.telegram.media', 'BQACAgIAAxkBAAIB_doc'))->assertOk();

        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_file_names_are_safe_in_the_download_header(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => "documents/счёт \"O'zbek\"\r\nX-Evil: 1.pdf"]]),
            'api.telegram.org/file/*' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.telegram.media', 'BQACAgIAAxkBAAIB_doc'))->assertOk();
        $disposition = $response->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment; filename=', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertNull($response->headers->get('X-Evil'));
    }

    public function test_missing_media_returns_404(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'file is too big'], 400)]);

        $this->actingAs($this->admin())->get(route('admin.telegram.media', 'AgACAgIAAxkBAAIB_photo'))->assertNotFound();
    }

    public function test_media_route_is_admin_only(): void
    {
        Http::fake();

        $this->get(route('admin.telegram.media', 'AgACAgIAAxkBAAIB_photo'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.telegram.media', 'AgACAgIAAxkBAAIB_photo'))
            ->assertRedirect();

        Http::assertNothingSent();
    }
}
