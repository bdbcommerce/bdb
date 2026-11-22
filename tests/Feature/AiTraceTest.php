<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiSandboxChat;
use App\Models\AiTrace;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ClientRequest;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** The sandbox (real Claude turn, nothing reaches Telegram) and dev-mode traces of real customer turns. */
class AiTraceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public ?array $answer = null;

    public bool $fail = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private AiTraceTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                if ($this->test->fail) {
                    throw new RuntimeException('Claude API error 529: overloaded');
                }
                $this->test->calls[] = compact('system', 'data');

                return ['text' => json_encode($this->test->answer ?? AiTraceTest::reply()), 'model' => 'claude-opus-5-5', 'input_tokens' => 1200, 'output_tokens' => 80];
            }
        });
        Carbon::setTestNow(Carbon::parse('2026-11-23 20:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function reply(array $override = []): array
    {
        return $override + ['reply' => 'Printer modeli qanday?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'questions',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [['product' => 'Receipt printer', 'details' => 'prints blank']], 'summary' => 'Receipt printer prints blank paper.', 'confirmed' => false,
            'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false];
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_sandbox_turn_is_traced_and_nothing_reaches_telegram(): void
    {
        $admin = $this->admin();
        $javohir = AiEmployee::where('name', 'Javohir')->sole();
        $this->actingAs($admin)->post(route('admin.ai-employees.draft', $javohir), ['prompt' => 'Ask for the printer model first. DRAFT-MARK']);

        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.start'), ['ai_employee_id' => $javohir->id, 'prompt' => 'draft', 'via' => 'assistant'])->assertRedirect();
        $chat = AiSandboxChat::sole();
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.message', $chat), ['message' => 'Chek printer oq qog‘oz chiqaryapti'])->assertRedirect();

        $this->assertCount(1, $this->calls);
        $this->assertStringStartsWith('You are Javohir, technical support at BDB', $this->calls[0]['system']);
        $this->assertStringContainsString('DRAFT-MARK', $this->calls[0]['system'], 'the draft prompt is tried');
        $this->assertStringNotContainsString('SALES PLAYBOOK', $this->calls[0]['system'], 'the playbook is for sales consultants only');
        $this->assertStringContainsString('"chat":"premium account"', $this->calls[0]['data']);
        Http::assertNothingSent();
        $this->assertSame(0, ChatConversation::count());

        $trace = AiTrace::sole();
        $this->assertSame(['sandbox', 'sent', $chat->id, $javohir->id, 2], [$trace->source, $trace->status, $trace->sandbox_chat_id, $trace->ai_employee_id, $trace->prompt_version]);
        $this->assertSame(['customer', 'laravel', 'laravel', 'claude', 'claude', 'laravel', 'telegram'], array_column($trace->steps, 'where'));
        $this->assertSame([1200, 80], [$trace->input_tokens, $trace->output_tokens]);
        $this->assertNotNull($trace->cost_usd);
        $this->assertSame($this->calls[0]['system'], $trace->steps[3]['data']['system_prompt'], 'the exact prompt is recorded');
        $this->assertSame(['user', 'assistant'], array_column($chat->fresh()->messages, 'role'));
        $this->assertSame('questions', $chat->fresh()->state['stage']);

        $this->actingAs($admin)->get(route('admin.ai-team.sandbox', ['chat' => $chat->id]))->assertOk()
            ->assertSee('Printer modeli qanday?')->assertSee('Laravel → Claude API: request')->assertSee('Claude API → Laravel: answer')
            ->assertSee('Would be sent to the customer (sandbox: not sent)')->assertSee('Show (');
        $this->actingAs($admin)->get(route('admin.ai-team.traces', ['source' => 'sandbox']))->assertOk()->assertSee('Chek printer');
        $this->actingAs($admin)->get(route('admin.ai-team.trace', $trace))->assertOk()->assertSee('Open the sandbox chat');

        // Another admin cannot use this chat.
        $other = $this->admin();
        $this->actingAs($other)->post(route('admin.ai-team.sandbox.message', $chat), ['message' => 'x'])->assertNotFound();
        $this->actingAs($other)->get(route('admin.ai-team.sandbox', ['chat' => $chat->id]))->assertNotFound();
    }

    public function test_sandbox_confirmation_only_says_what_would_happen(): void
    {
        $admin = $this->admin();
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $this->answer = self::reply(['reply' => 'Rahmat!', 'stage' => 'handed_over', 'confirmed' => true, 'unknown_question' => 'Does it work with 1C?',
            'customer' => ['first_name' => 'Ali', 'last_name' => '', 'phone' => '901112233', 'city' => '', 'company' => '']]);
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.start'), ['ai_employee_id' => $malika->id, 'prompt' => 'published', 'via' => 'bot']);
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.message', AiSandboxChat::sole()), ['message' => 'Ha, 901112233']);

        $this->assertStringContainsString('# SALES PLAYBOOK', $this->calls[0]['system']);
        $this->assertSame(0, ChatLead::count());
        $this->assertSame(0, ClientRequest::count());
        Http::assertNothingSent();
        $would = AiTrace::sole()->steps[5]['data']['would'];
        $this->assertContains('Would post the request to the sales chat', $would);
        $this->assertContains('Would post the unknown question to the source group and Client requests', $would);
    }

    public function test_sandbox_failure_is_traced(): void
    {
        $this->fail = true;
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.start'), ['ai_employee_id' => AiEmployee::first()->id, 'prompt' => 'published', 'via' => 'bot']);
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.message', AiSandboxChat::sole()), ['message' => 'Salom']);
        $trace = AiTrace::sole();
        $this->assertSame('failed', $trace->status);
        $this->assertSame('Claude API error 529: overloaded', collect($trace->steps)->last()['data']['error']);
        $this->assertSame(['user'], array_column(AiSandboxChat::sole()->messages, 'role'));
    }

    private function bot(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_dev_mode_traces_real_customer_turns(): void
    {
        $this->bot(1, 'Salom, printer kerak');
        $this->assertCount(1, $this->calls);
        $this->assertSame(0, AiTrace::count(), 'dev mode off: nothing recorded');

        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.ai-team.dev-mode'), ['dev_mode' => '1'])->assertSessionHas('success');
        $this->assertTrue(SalesAssistantSetting::current()->dev_mode);
        auth()->logout();

        $this->bot(2, 'Chek printer');
        $trace = AiTrace::sole();
        $c = ChatConversation::sole();
        $this->assertSame(['live', 'sent', 'reply', $c->id], [$trace->source, $trace->status, $trace->kind, $trace->chat_conversation_id]);
        $this->assertSame('Chek printer', $trace->customer_text);
        $this->assertSame(AiEmployee::where('name', 'Malika')->value('id'), $trace->ai_employee_id);
        $this->assertSame(['customer', 'laravel', 'laravel', 'claude', 'claude', 'laravel', 'telegram'], array_column($trace->steps, 'where'));
        $this->assertStringStartsWith('You are Malika', $trace->steps[3]['data']['system_prompt']);
        $this->assertSame('bot chat', $trace->steps[6]['data']['chat']);

        $this->actingAs($admin)->get(route('admin.ai-team.traces'))->assertOk()->assertSee('Chek printer')->assertSee('Malika · v1');
        $this->actingAs($admin)->get(route('admin.ai-team.trace', $trace))->assertOk()
            ->assertSee('Customer')->assertSee('Laravel backend')->assertSee('Claude API')->assertSee('Telegram')->assertSee('Open the conversation');

        $this->actingAs($admin)->put(route('admin.ai-team.dev-mode'), ['dev_mode' => '0']);
        $this->assertFalse(SalesAssistantSetting::current()->dev_mode);
    }

    public function test_pages_are_admin_only(): void
    {
        foreach ([route('admin.ai-team.sandbox'), route('admin.ai-team.traces')] as $url) {
            $this->get($url)->assertRedirect();
        }
        $this->put(route('admin.ai-team.dev-mode'), ['dev_mode' => '1'])->assertRedirect();
        $this->assertFalse((bool) SalesAssistantSetting::current()->dev_mode);
        $this->actingAs($this->admin())->get(route('admin.ai-team.sandbox'))->assertOk()->assertSee('Start a test chat on the left');
    }
}
