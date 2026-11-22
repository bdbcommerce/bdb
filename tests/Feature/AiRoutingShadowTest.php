<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiShadowDraft;
use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Routing between AI employees (hand_to) and shadow mode (a manager sends, edits or discards the AI's reply). */
class AiRoutingShadowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        $n = 900;
        Http::fake(['api.telegram.org/*' => function () use (&$n) {
            return Http::response(['ok' => true, 'result' => ['message_id' => $n++]]);
        }]);
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private AiRoutingShadowTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('system', 'data');
                $answer = count($this->test->answers) > 1 ? array_shift($this->test->answers) : ($this->test->answers[0] ?? []);

                return ['text' => json_encode($answer), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        Carbon::setTestNow(Carbon::parse('2026-11-23 20:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function answer(array $override = []): array
    {
        return $override + ['reply' => 'Qanday yordam kerak?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => 'Printer problem.', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'hand_to' => ''];
    }

    private function bot(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function sentTo(string $chat): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) ($r->data()['chat_id'] ?? '') === $chat)
            ->map(fn ($p) => $p[0]->data()['text'])->values()->all();
    }

    private function javohir(string $status): AiEmployee
    {
        return tap(AiEmployee::where('name', 'Javohir')->sole())->update(['status' => $status]);
    }

    public function test_sales_hands_a_printer_problem_to_support_who_answers_at_once(): void
    {
        $this->javohir('live');
        $this->answers = [
            $this->answer(['reply' => 'Bu savolga texnik mutaxassisimiz Javohir yordam beradi.', 'hand_to' => 'support']),
            $this->answer(['reply' => 'Salom, men Javohir. Printer modeli qanday?']),
            $this->answer(['reply' => 'Rahmat. Qog‘oz to‘g‘ri qo‘yilganmi?']),
        ];
        $this->bot(1, 'Chek printer oq qog‘oz chiqaryapti');

        $this->assertCount(2, $this->calls);
        $this->assertStringStartsWith('You are Malika', $this->calls[0]['system']);
        $this->assertStringContainsString("# YOUR COLLEAGUES (hand the customer over with hand_to when the need is theirs)\n- Javohir, technical support — hand_to: support", $this->calls[0]['system']);
        $this->assertStringNotContainsString('Dilshod', $this->calls[0]['system'], 'a draft colleague is not offered');
        $this->assertStringStartsWith('You are Javohir, technical support', $this->calls[1]['system']);
        $this->assertStringNotContainsString('SALES PLAYBOOK', $this->calls[1]['system']);
        $this->assertStringContainsString('Your colleague Malika has just handed this customer to you', $this->calls[1]['data']);
        $this->assertSame(['Bu savolga texnik mutaxassisimiz Javohir yordam beradi.', 'Salom, men Javohir. Printer modeli qanday?'], $this->sentTo('7001'));

        $c = ChatConversation::sole();
        $this->assertSame('support', $c->sales_state['kind']);
        $this->assertSame(['Malika', 'Javohir'], array_values(array_filter(array_column($c->messages, 'employee'))));

        // The next message goes straight to Javohir.
        $this->bot(2, 'Epson TM-T20');
        $this->assertCount(3, $this->calls);
        $this->assertStringStartsWith('You are Javohir', $this->calls[2]['system']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.chat-conversations.show', $c))->assertOk()
            ->assertSee('AI · Malika')->assertSee('AI · Javohir');
    }

    public function test_a_hand_to_a_draft_colleague_is_ignored(): void
    {
        $this->answers = [$this->answer(['reply' => 'Qaysi printer?', 'hand_to' => 'support'])];
        $this->bot(1, 'Printer ishlamayapti');
        $this->assertCount(1, $this->calls);
        $this->assertStringNotContainsString('# YOUR COLLEAGUES', $this->calls[0]['system']);
        $this->assertArrayNotHasKey('kind', ChatConversation::sole()->sales_state);
    }

    public function test_shadow_replies_wait_for_a_manager_who_sends_edits_or_discards(): void
    {
        $this->javohir('shadow');
        $this->answers = [
            $this->answer(['reply' => 'Javohir yordam beradi.', 'hand_to' => 'support']),
            $this->answer(['reply' => 'Printer modelini yozing.']),
            $this->answer(['reply' => 'Rolikni tekshiring.']),
        ];
        $this->bot(1, 'Printer oq qog‘oz chiqaryapti');

        $this->assertSame(['Javohir yordam beradi.'], $this->sentTo('7001'), 'the shadow reply is not sent');
        $draft = AiShadowDraft::sole();
        $this->assertSame(['pending', 'Printer modelini yozing.'], [$draft->status, $draft->draft]);
        $sales = $this->sentTo('-100555');
        $this->assertCount(1, $sales);
        $this->assertStringContainsString('Shadow reply — Javohir', $sales[0]);

        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Aziz']);
        $c = ChatConversation::sole();
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $c))->assertOk()->assertSee('Printer modelini yozing.')->assertSee('Send to the customer');
        $this->actingAs($admin)->get(route('admin.ai-team.shadow'))->assertOk()->assertSee('Waiting for a manager (1)')->assertSee('AI replies waiting for a manager');

        $this->actingAs($admin)->post(route('admin.ai-team.shadow.send', $draft), ['text' => 'Printer modelini yozing, iltimos.'])->assertSessionHasNoErrors();
        $this->assertSame('Printer modelini yozing, iltimos.', $this->sentTo('7001')[1]);
        $this->assertSame(['edited', 'Aziz'], [$draft->fresh()->status, $draft->fresh()->decided_by]);
        $last = collect(ChatConversation::sole()->messages)->last();
        $this->assertSame(['assistant', 'Javohir', 'Aziz'], [$last['role'], $last['employee'], $last['approved_by']]);
        $this->actingAs($admin)->post(route('admin.ai-team.shadow.send', $draft), ['text' => 'again'])->assertSessionHasErrors('draft');

        // A newer customer turn: a new draft; discarded by the manager.
        $this->bot(2, 'Epson');
        $second = AiShadowDraft::latest('id')->first();
        $this->assertSame('Rolikni tekshiring.', $second->draft);
        $this->actingAs($admin)->post(route('admin.ai-team.shadow.discard', $second));
        $this->assertSame('discarded', $second->fresh()->status);
        $this->assertSame(['decided' => 2, 'sent' => 0, 'edited' => 1, 'discarded' => 1, 'rate' => 0], AiShadowDraft::stats($this->javohir('shadow')->id));
        $this->assertCount(2, $this->sentTo('7001'));
    }

    public function test_a_newer_turn_makes_the_waiting_draft_stale_and_a_shadow_sales_consultant_sends_nothing(): void
    {
        AiEmployee::where('name', 'Malika')->update(['status' => 'shadow']);
        $this->answers = [$this->answer(['reply' => 'Birinchi']), $this->answer(['reply' => 'Ikkinchi'])];
        $this->bot(1, 'Salom');
        $this->bot(2, 'Etiketka kerak');
        $this->assertSame([], $this->sentTo('7001'));
        $this->assertSame(['stale', 'pending'], AiShadowDraft::orderBy('id')->pluck('status')->all());
        $this->assertTrue(ChatConversation::sole()->awaiting_reply, 'still waiting for a person');
    }

    public function test_shadow_status_rules(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $j = AiEmployee::where('name', 'Javohir')->sole();
        $card = ['name' => $j->name, 'role' => $j->role, 'kind' => 'support', 'status' => 'shadow', 'scales' => $j->scales, 'channels' => $j->channels];
        $this->actingAs($admin)->put(route('admin.ai-employees.update', $j), $card)->assertSessionHasNoErrors();
        $this->assertTrue($j->fresh()->isShadow());
        $d = AiEmployee::where('name', 'Dilshod')->sole();
        $this->actingAs($admin)->put(route('admin.ai-employees.update', $d), ['kind' => 'support'] + $card + ['name' => 'Dilshod'])->assertSessionHasErrors('status');
        $this->get(route('admin.ai-team.shadow'))->assertOk();
        auth()->logout();
        $this->get(route('admin.ai-team.shadow'))->assertRedirect();
    }
}
