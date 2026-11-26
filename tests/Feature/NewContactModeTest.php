<?php

namespace Tests\Feature;

use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sales assistant → Settings → New contact: smart (default) / always greet / just create. */
class NewContactModeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public bool $continues = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on', 'require_assignment' => true]);
        $id = 899;
        Http::fake(['api.telegram.org/*' => function () use (&$id) {
            return Http::response(['ok' => true, 'result' => ['message_id' => ++$id]]);
        }]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Tashkent'));
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private NewContactModeTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;

                return ['text' => json_encode(['reply' => 'Assalomu alaykum! BDB.', 'language' => 'uz', 'context_unknown' => $this->test->continues]), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function say(int $id, array $message = []): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => $message + ['text' => 'Salom', 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function toCustomer(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())
            ->filter(fn ($m) => (string) ($m['chat_id'] ?? '') === '7001')->values()->all();
    }

    private function askedToAssign(): bool
    {
        return TelegramUser::where('telegram_id', '7001')->value('assignment_requested_at') !== null;
    }

    public function test_smart_is_the_default_and_greets_a_new_customer(): void
    {
        $this->assertSame('smart', SalesAssistantSetting::current()->newContactMode());
        $this->say(1);
        $this->assertCount(1, $this->toCustomer());
        $this->assertTrue($this->askedToAssign());
    }

    public function test_smart_does_not_greet_a_continued_talk(): void
    {
        $this->continues = true;
        $this->say(1, ['text' => 'Kecha aytgan narxingiz boʻyicha toʻlov qildim']);
        $this->assertSame([], $this->toCustomer());
        $this->assertTrue($this->askedToAssign());

        // A reply to a message we never recorded: no greeting call at all.
        TelegramUser::query()->delete();
        $this->calls = [];
        $this->say(20, ['message_id' => 20, 'reply_to_message' => ['message_id' => 5, 'date' => now()->getTimestamp(), 'chat' => ['id' => 7001, 'type' => 'private']]]);
        $this->assertSame([], $this->calls);
    }

    public function test_always_greets_even_a_continued_talk(): void
    {
        SalesAssistantSetting::current()->update(['new_contact_mode' => 'always']);
        $this->continues = true;
        $this->say(1, ['text' => 'Kecha aytgan narxingiz boʻyicha toʻlov qildim']);
        $this->assertCount(1, $this->toCustomer());
        $this->assertNotNull(TelegramUser::where('telegram_id', '7001')->value('greeted_at'));
    }

    public function test_create_sends_nothing_and_only_asks_to_assign(): void
    {
        SalesAssistantSetting::current()->update(['new_contact_mode' => 'create']);
        $this->say(1);
        $this->assertSame([], $this->toCustomer());
        $this->assertSame([], $this->calls);
        $this->assertTrue($this->askedToAssign());
        $this->assertNull(TelegramUser::where('telegram_id', '7001')->value('greeted_at'));
    }

    public function test_settings_page_saves_the_mode(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->assertSee('New contact')->assertSee('Just create, no greeting');
        $s = SalesAssistantSetting::current();
        $form = ['bot_mode' => 'on', 'business_mode' => 'on', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 2, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => $s->model];
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $form + ['new_contact_mode' => 'create'])->assertSessionHasNoErrors();
        $this->assertSame('create', $s->refresh()->newContactMode());
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $form + ['new_contact_mode' => 'shout'])->assertSessionHasErrors('new_contact_mode');
    }
}
