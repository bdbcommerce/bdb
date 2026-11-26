<?php

namespace Tests\Feature;

use App\Models\ChatLead;
use App\Models\Client;
use App\Models\ClientPerson;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistantAi;
use Database\Seeders\DemoAiTeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Settings → Data sent to Claude: phones, @username, photos and the client portrait can be kept out of a turn. */
class ClaudeDataSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(['api.telegram.org/bot123:abc/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/a.jpg']]),
            'api.telegram.org/file/*' => Http::response(base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private ClaudeDataSettingsTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('system', 'data', 'images');

                return ['text' => json_encode($this->test->answer), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        $this->answer = $this->reply();
    }

    private function reply(array $override = []): array
    {
        return $override + ['reply' => 'Ok', 'language' => 'ru', 'ask_phone_button' => false, 'stage' => 'need',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false];
    }

    private function say(int $id, array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => $message + ['message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali', 'username' => 'ali_shop']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function off(string ...$keys): void
    {
        SalesAssistantSetting::current()->update(['claude_data' => array_fill_keys($keys, false)]);
    }

    public function test_everything_is_sent_by_default(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'username' => 'ali_shop', 'phone' => '+998901112233', 'step' => TelegramUser::STEP_DONE, 'contact_type' => 'client']);
        $this->say(1, ['text' => 'Mening raqamim 90 765 43 21, skaner kerak']);

        $data = $this->calls[0]['data'];
        $this->assertStringContainsString('phone +998901112233', $data);
        $this->assertStringContainsString('90 765 43 21', $data);
        $this->assertStringContainsString('@ali_shop', $data);
    }

    public function test_phones_hidden_but_the_typed_phone_still_reaches_the_lead(): void
    {
        $this->off('phone');
        $this->answer = $this->reply(['confirmed' => true, 'summary' => '2 scanners', 'stage' => 'confirm',
            'customer' => ['first_name' => 'Ali', 'last_name' => '', 'phone' => '[phone]', 'city' => '', 'company' => '']]);
        $this->say(1, ['text' => 'Mening raqamim +998 90 765 43 21, 2 ta skaner kerak, tasdiqlayman']);

        $data = $this->calls[0]['data'];
        $this->assertStringNotContainsString('765 43 21', $data);
        $this->assertStringContainsString('[phone]', $data);
        $this->assertStringContainsString('Phone numbers are hidden from you', $data);
        // Laravel read the number itself: the card and the lead have it, unverified.
        $user = TelegramUser::where('telegram_id', '7001')->sole();
        $this->assertSame('+998907654321', $user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertSame('+998907654321', ChatLead::sole()->phone);
    }

    public function test_a_known_phone_is_only_said_to_be_known(): void
    {
        $this->off('phone');
        TelegramUser::create(['telegram_id' => '7001', 'phone' => '+998901112233', 'step' => TelegramUser::STEP_DONE, 'contact_type' => 'client']);
        $this->say(1, ['text' => 'Salom']);

        $this->assertStringNotContainsString('+998901112233', $this->calls[0]['data']);
        $this->assertStringContainsString('phone known (the number is kept by us)', $this->calls[0]['data']);
    }

    public function test_username_photos_and_portrait_can_be_left_out(): void
    {
        $user = TelegramUser::create(['telegram_id' => '7001', 'username' => 'ali_shop', 'step' => TelegramUser::STEP_DONE, 'contact_type' => 'client']);
        $client = Client::create(['name' => 'Ali Market', 'status' => 'active']);
        ClientPerson::create(['client_id' => $client->id, 'telegram_user_id' => $user->id, 'name' => 'Ali', 'position' => 'Owner', 'status' => 'active']);

        $this->say(1, ['photo' => [['file_id' => 'PHOTO1', 'width' => 1, 'height' => 1]]]);
        $this->assertCount(1, $this->calls[0]['images']);
        $this->assertStringContainsString('@ali_shop', $this->calls[0]['data']);
        $this->assertStringContainsString('Client portrait: Ali', $this->calls[0]['data']);

        $this->off('username', 'photos', 'portrait');
        $this->say(2, ['photo' => [['file_id' => 'PHOTO2', 'width' => 1, 'height' => 1]]]);
        $last = end($this->calls);
        $this->assertSame([], $last['images']);
        $this->assertStringNotContainsString('@ali_shop', $last['data']);
        $this->assertStringNotContainsString('Client portrait', $last['data']);
        $this->assertStringContainsString('Photos are not shown to you', $last['data']);
    }

    public function test_settings_page_saves_the_switches(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->assertSee('Data sent to Claude')->assertSee('Phone numbers');

        $s = SalesAssistantSetting::current();
        $form = ['bot_mode' => 'on', 'business_mode' => 'on', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 2, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => $s->model,
            'claude_data_section' => 1, 'claude_data' => ['photos' => '1', 'portrait' => '1']];
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $form)->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame(['phone' => false, 'username' => false, 'photos' => true, 'portrait' => true], $s->claude_data);
        $this->assertFalse($s->sendsToClaude('phone'));

        // A form without the section (older page) leaves the switches as they are.
        unset($form['claude_data_section'], $form['claude_data']);
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $form)->assertSessionHasNoErrors();
        $this->assertFalse($s->refresh()->sendsToClaude('phone'));
    }

    public function test_demo_seeder_runs_twice_without_duplicates(): void
    {
        $this->seed(DemoAiTeamSeeder::class);
        $this->seed(DemoAiTeamSeeder::class);
        $this->assertSame(8, TelegramUser::where('telegram_id', 'like', '9000000%')->count());
        $this->assertSame(1, TelegramUser::where('is_main_admin', true)->count());
    }
}
