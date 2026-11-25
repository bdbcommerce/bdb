<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\ExpertQuestion;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\SalesKnowledge;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Ai\VoiceTranscriber;
use App\Services\AiErrorAlerts;
use App\Services\SalesAssistant\ExpertDesk;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Expert questions: the AI asks employees in the bot chat, one at a time; answers reach the customer and become knowledge drafts. */
class ExpertDeskTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{system: string, data: string}> */
    public array $calls = [];

    /** @var list<array> what Claude answers next (the last one repeats) */
    public array $answers = [];

    /** @var list<array> Telegram calls */
    private array $sent = [];

    private int $update = 1;

    protected function setUp(): void
    {
        parent::setUp();
        // A Monday, 10:00 Tashkent: working time.
        $this->travelTo(Carbon::parse('2026-11-23 10:00', 'Asia/Tashkent'));
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => 's', 'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(function (Request $request) {
            $this->sent[] = ['url' => $request->url(), 'data' => $request->data()];
            if (str_contains($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_id' => 'v1', 'file_path' => 'voice/file_1.oga', 'file_size' => 2000]]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response('OggS'.str_repeat("\0", 200));
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 900 + count($this->sent)]]);
        });
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private ExpertDeskTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('system', 'data');
                $answer = count($this->test->answers) > 1 ? array_shift($this->test->answers) : ($this->test->answers[0] ?? []);

                return ['text' => json_encode($answer + ExpertDeskTest::blank()), 'model' => $model, 'input_tokens' => 100, 'output_tokens' => 10];
            }
        });
    }

    public static function blank(): array
    {
        return ['reply' => 'OK', 'language' => 'ru', 'ask_phone_button' => false, 'stage' => 'questions',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'unknown_topic' => '', 'unknown_kind' => '',
            'expert_draft' => '', 'expert_department' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'hand_to' => '', 'context_unknown' => false];
    }

    private function expert(int $id, string $role, array $over = []): TelegramUser
    {
        return TelegramUser::create($over + ['telegram_id' => (string) $id, 'name' => 'Expert '.$id, 'contact_type' => 'employee', 'is_employee' => true,
            'employee_role' => $role, 'answers_questions' => true, 'locale' => 'ru', 'bot_chat_at' => now()]);
    }

    private function customerWrites(int $chat, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->update++, 'message' => ['message_id' => $this->update, 'date' => now()->getTimestamp(), 'text' => $text,
            'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'Ali']]], ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    private function expertWrites(TelegramUser $expert, array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->update++, 'message' => $message + ['message_id' => $this->update, 'date' => now()->getTimestamp(),
            'chat' => ['id' => (int) $expert->telegram_id, 'type' => 'private'], 'from' => ['id' => (int) $expert->telegram_id, 'first_name' => 'E']]], ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    private function press(TelegramUser $expert, string $data): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->update++, 'callback_query' => ['id' => 'cb'.$this->update, 'data' => $data,
            'from' => ['id' => (int) $expert->telegram_id]]], ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    /** Messages sent to one chat. @return list<array> */
    private function to(string|int $chat): array
    {
        return array_values(array_filter($this->sent, fn ($s) => str_contains($s['url'], '/sendMessage') && (string) ($s['data']['chat_id'] ?? '') === (string) $chat));
    }

    private function lastTo(string|int $chat): array
    {
        $all = $this->to($chat);

        return $all ? $all[array_key_last($all)] : ['data' => ['text' => '']];
    }

    private function lastCall(): array
    {
        return $this->calls ? $this->calls[array_key_last($this->calls)] : ['data' => '', 'system' => ''];
    }

    private function unknown(string $question, string $topic, string $draft = '', string $department = 'Service'): array
    {
        return ['reply' => 'Уточню у нашего специалиста и вернусь с ответом.', 'unknown_question' => $question, 'unknown_topic' => $topic,
            'unknown_kind' => 'device', 'expert_draft' => $draft, 'expert_department' => $department];
    }

    public function test_the_question_goes_to_the_expert_of_its_department_not_the_group(): void
    {
        $service = $this->expert(501, 'Service');
        $this->expert(502, 'Sales');
        $this->answers = [$this->unknown('Does ZD421 print on 25 mm core?', 'label core size for zd421', 'Yes, with the adapter.')];

        $this->customerWrites(7001, 'ZD421 25 mm vtulka bilan ishlaydimi?');

        $q = ExpertQuestion::sole();
        $this->assertSame('asked', $q->status);
        $this->assertSame($service->id, $q->expert_id);
        $this->assertSame('Yes, with the adapter.', $q->ai_draft);
        $this->assertNotNull($q->client_request_id);
        $toExpert = $this->to(501);
        $this->assertCount(1, $toExpert);
        $this->assertStringContainsString('Does ZD421 print on 25 mm core?', $toExpert[0]['data']['text']);
        $this->assertStringContainsString('Yes, with the adapter.', $toExpert[0]['data']['text']);
        $this->assertSame("xq:{$q->id}:accept", $toExpert[0]['data']['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame([], $this->to('-1003333333333'), 'not the source group');
        $this->assertSame([], $this->to(502), 'another department gets nothing');
        $this->assertStringContainsString('Specialists: available — departments: Sales, Service', $this->calls[0]['data']);
        $this->assertStringContainsString('Уточню', $this->to(7001)[0]['data']['text'], 'the customer is told once');
    }

    public function test_without_experts_the_source_group_gets_it_as_before(): void
    {
        $this->answers = [$this->unknown('Q?', 'topic a') + ['reply' => '']];
        $this->customerWrites(7001, 'Savol?');

        $this->assertSame(0, ExpertQuestion::count());
        $this->assertCount(1, $this->to('-1003333333333'));
        $this->assertStringNotContainsString('Specialists: available', $this->calls[0]['data']);
    }

    public function test_accepting_the_ai_idea_answers_the_customer_and_drafts_knowledge(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Core 25 mm?', 'label core size', 'Yes, with the adapter.'), ['reply' => 'Специалист подтвердил: да, с адаптером.']];
        $this->customerWrites(7001, 'Vtulka 25 mm?');
        $q = ExpertQuestion::sole();

        $this->press($expert, "xq:{$q->id}:accept");

        $q->refresh();
        $this->assertSame('answered', $q->status);
        $this->assertSame('accepted', $q->verdict);
        $this->assertSame('Yes, with the adapter.', $q->answer);
        $this->assertSame('sent', $q->delivered);
        $this->assertStringContainsString('# TASK NOW', $this->lastCall()['data']);
        $this->assertStringContainsString('Yes, with the adapter.', $this->lastCall()['data']);
        $this->assertSame('Специалист подтвердил: да, с адаптером.', $this->lastTo(7001)['data']['text']);
        $knowledge = SalesKnowledge::find($q->sales_knowledge_id);
        $this->assertFalse($knowledge->is_active, 'a draft until sales approves it');
        $this->assertSame('device', $knowledge->kind);
        $this->assertSame('resolved', ClientRequest::find($q->client_request_id)->status);
        $this->assertStringContainsString('Answers from our specialists', $this->lastCall()['data'] ?? '');
    }

    public function test_one_question_at_a_time_per_expert(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('First?', 'topic one')];
        $this->customerWrites(7001, 'Birinchi savol');
        $this->answers = [$this->unknown('Second?', 'topic two')];
        $this->customerWrites(7002, 'Ikkinchi savol');

        $this->assertCount(1, $this->to(501));
        $second = ExpertQuestion::where('question', 'Second?')->sole();
        $this->assertSame('queued', $second->status);

        $this->answers = [['reply' => 'Passing it on']];
        $this->expertWrites($expert, ['text' => 'The first answer']);

        $this->assertSame('written', ExpertQuestion::where('question', 'First?')->sole()->verdict);
        $this->assertSame('asked', $second->fresh()->status);
        $this->assertStringContainsString('Second?', $this->lastTo(501)['data']['text']);
        $this->assertSame(0, ChatConversation::where('external_id', '501')->count(), 'the expert\'s answers are not a customer chat');
    }

    public function test_the_same_topic_from_two_customers_gets_one_answer(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Delivery to Samarkand?', 'delivery to samarkand')];
        $this->customerWrites(7001, 'Samarqandga yetkazasizmi?');
        $this->customerWrites(7002, 'Доставка в Самарканд?');

        $parent = ExpertQuestion::whereNull('parent_id')->sole();
        $this->assertSame(1, $parent->children()->where('status', 'grouped')->count());
        $this->assertStringContainsString('(всего ждут: 2)', $this->lastTo(501)['data']['text']);

        $this->answers = [['reply' => 'Да, 2 дня.']];
        $this->expertWrites($expert, ['text' => 'Yes, 2 days by courier']);

        $this->assertSame(2, ExpertQuestion::where('status', 'answered')->where('delivered', 'sent')->count());
        $this->assertSame('Да, 2 дня.', $this->lastTo(7001)['data']['text']);
        $this->assertSame('Да, 2 дня.', $this->lastTo(7002)['data']['text']);
    }

    public function test_transfer_with_a_note_then_the_main_admin_after_three(): void
    {
        $service = $this->expert(501, 'Service');
        $sales = $this->expert(502, 'Sales');
        $admin = $this->expert(503, 'CEO', ['answers_questions' => false, 'is_main_admin' => true]);
        $this->answers = [$this->unknown('Credit terms?', 'credit terms')];
        $this->customerWrites(7001, 'Nasiya bormi?');
        $q = ExpertQuestion::sole();

        $this->press($service, "xq:{$q->id}:transfer");
        $buttons = $this->lastTo(501)['data']['reply_markup']['inline_keyboard'];
        $this->assertSame('Sales', $buttons[0][0]['text']);
        $this->assertSame("xq:{$q->id}:to:admin", end($buttons)[0]['callback_data']);
        $this->press($service, $buttons[0][0]['callback_data']);
        $this->expertWrites($service, ['text' => 'This is about money, not devices']);

        $q->refresh();
        $this->assertSame($sales->id, $q->expert_id);
        $this->assertSame(1, $q->transfers);
        $this->assertStringContainsString('This is about money', $this->lastTo(502)['data']['text']);

        // Back to Service, whose only expert already passed it on: nobody left there, so the main admin.
        $this->press($sales, "xq:{$q->id}:transfer");
        $this->press($sales, "xq:{$q->id}:to:".ExpertDesk::code('Service')); // departments: Sales (0), Service (1)
        $this->press($sales, "xq:{$q->id}:skip");
        $q->refresh();
        $this->assertSame(2, $q->transfers);
        $this->assertSame($admin->id, $q->expert_id);

        // The third transfer always ends with the main admin.
        $q->update(['status' => 'asked', 'expert_id' => $sales->id, 'to_admin' => false, 'declined_by' => []]);
        $this->press($sales, "xq:{$q->id}:to:".ExpertDesk::code('Service'));
        $this->press($sales, "xq:{$q->id}:skip");
        $q->refresh();
        $this->assertTrue($q->to_admin);
        $this->assertSame(3, $q->transfers);
    }

    public function test_reminder_then_the_main_admin_in_working_minutes(): void
    {
        $service = $this->expert(501, 'Service');
        $admin = $this->expert(503, 'CEO', ['answers_questions' => false, 'is_main_admin' => true]);
        $this->answers = [$this->unknown('Q?', 'topic')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();

        $this->travel(31)->minutes();
        app(ExpertDesk::class)->tick();
        $this->assertNotNull($q->fresh()->reminded_at);
        $this->assertStringContainsString('Напоминание', $this->lastTo(501)['data']['text']);

        $this->travel(30)->minutes();
        app(ExpertDesk::class)->tick();
        $q->refresh();
        $this->assertSame($admin->id, $q->expert_id);
        $this->assertTrue($q->to_admin);
        $this->assertCount(1, $this->to(503));
    }

    public function test_outside_working_hours_questions_wait_for_the_morning(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 20:00', 'Asia/Tashkent'));
        SalesAssistantSetting::current()->update(['off_hours_mode' => 'ai']);
        $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Q?', 'topic')];
        $this->customerWrites(7001, 'Savol');

        $this->assertSame('queued', ExpertQuestion::sole()->status);
        $this->assertSame([], $this->to(501));
        $this->assertStringContainsString('they answer in working hours, next from Tue 24.11 09:00', $this->calls[0]['data']);

        $this->travelTo(Carbon::parse('2026-11-24 09:01', 'Asia/Tashkent'));
        app(ExpertDesk::class)->tick();
        $this->assertSame('asked', ExpertQuestion::sole()->status);
    }

    public function test_a_voice_answer_is_transcribed_and_sent_after_confirmation(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->app->instance(VoiceTranscriber::class, new class(app(AiErrorAlerts::class)) extends VoiceTranscriber
        {
            public function transcribe(string $audio, string $filename = 'voice.ogg'): ?array
            {
                return ['text' => 'Ha, adapter bilan ishlaydi', 'seconds' => 3.0];
            }
        });
        $this->answers = [$this->unknown('Q?', 'topic')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();

        $this->expertWrites($expert, ['voice' => ['file_id' => 'v1', 'duration' => 3, 'mime_type' => 'audio/ogg']]);
        $this->assertStringContainsString('Ha, adapter bilan ishlaydi', $this->lastTo(501)['data']['text']);
        $this->assertSame('asked', $q->fresh()->status, 'not sent before the expert confirms');

        $this->answers = [['reply' => 'Работает с адаптером.']];
        $this->press($expert, "xq:{$q->id}:send");
        $this->assertSame('Ha, adapter bilan ishlaydi', $q->fresh()->answer);
        $this->assertSame('Работает с адаптером.', $this->lastTo(7001)['data']['text']);
    }

    public function test_dont_know_goes_to_the_managers(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Q?', 'topic')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();

        $this->press($expert, "xq:{$q->id}:unknown");

        $this->assertSame('unknown', $q->fresh()->status);
        $this->assertCount(1, $this->to('-1003333333333'));
        $this->assertSame('open', ClientRequest::find($q->client_request_id)->status);
    }

    public function test_buttons_work_only_for_the_expert_holding_the_question(): void
    {
        $expert = $this->expert(501, 'Service');
        $other = $this->expert(502, 'Service');
        $this->answers = [$this->unknown('Q?', 'topic', 'Idea')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();
        $holder = $q->expert_id === $expert->id ? $expert : $other;
        $notHolder = $holder->is($expert) ? $other : $expert;

        $this->press($notHolder, "xq:{$q->id}:accept");
        $this->assertSame('asked', $q->fresh()->status);

        $stranger = TelegramUser::create(['telegram_id' => '9999', 'contact_type' => 'client']);
        $before = count($this->sent);
        $this->press($stranger, "xq:{$q->id}:accept");
        $this->assertSame('asked', $q->fresh()->status);
        $this->assertCount($before, $this->sent, 'a stranger gets no reply at all');
    }

    public function test_admin_page_and_knowledge_approval(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Core 25 mm?', 'label core size', 'Yes.'), ['reply' => 'Да.']];
        $this->customerWrites(7001, 'Vtulka?');
        $q = ExpertQuestion::sole();
        $this->press($expert, "xq:{$q->id}:accept");

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.expert-questions.index', ['filter' => 'review']))->assertOk()
            ->assertSee('Core 25 mm?')->assertSee('Accepted the AI idea')->assertSee('Knowledge draft');
        $this->actingAs($admin)->put(route('admin.expert-questions.approve', $q->fresh()), ['kind' => 'device', 'title' => 'ZD421 core', 'body' => 'Works with a 25 mm core adapter.'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(SalesKnowledge::find($q->fresh()->sales_knowledge_id)->is_active);
        $this->assertStringContainsString('Works with a 25 mm core adapter.', SalesKnowledge::forPrompt());

        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.expert-questions.index'))->assertRedirect();
    }

    public function test_user_page_ticks_and_one_main_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $a = $this->expert(501, 'Service', ['is_main_admin' => true]);
        $b = TelegramUser::create(['telegram_id' => '502']);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $b), ['contact_type' => 'employee', 'employee_role' => 'Sales', 'answers_questions' => '1', 'is_main_admin' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($b->fresh()->isExpert());
        $this->assertTrue($b->fresh()->is_main_admin);
        $this->assertFalse($a->fresh()->is_main_admin, 'only one main admin');

        $this->actingAs($admin)->put(route('admin.telegram-users.update', $b), ['contact_type' => 'client', 'answers_questions' => '1', 'is_main_admin' => '1']);
        $this->assertFalse($b->fresh()->answers_questions, 'clients never answer AI questions');
        $this->assertFalse($b->fresh()->is_main_admin);
    }

    public function test_after_a_manager_wrote_the_answer_goes_to_the_managers_not_the_ai(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Q?', 'topic', 'Idea')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();
        $conversation = ChatConversation::sole();
        $conversation->update(['messages' => [...$conversation->messages, ['role' => 'admin', 'content' => 'Я помогу', 'at' => now()->toIso8601String()]]]);
        $before = count($this->to(7001));

        $this->press($expert, "xq:{$q->id}:accept");

        $this->assertSame('manager', $q->fresh()->delivered);
        $this->assertCount($before, $this->to(7001), 'the AI does not write after a manager');
        $this->assertStringContainsString('Idea', $this->lastTo('-1003333333333')['data']['text']);
    }

    public function test_after_mark_answered_the_ai_does_not_deliver(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Q?', 'topic', 'Idea')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();
        $conversation = ChatConversation::sole();
        $conversation->forceFill(['handled_count' => count($conversation->messages)])->save();

        $this->press($expert, "xq:{$q->id}:accept");

        $this->assertSame('manager', $q->fresh()->delivered);
    }

    public function test_an_unreachable_department_expert_does_not_block_the_main_admin(): void
    {
        $this->expert(501, 'Service', ['bot_chat_at' => null]); // never pressed /start
        $admin = $this->expert(503, 'CEO', ['answers_questions' => false, 'is_main_admin' => true]);
        $this->answers = [$this->unknown('Q?', 'topic')];
        $this->customerWrites(7001, 'Savol');

        $this->assertSame($admin->id, ExpertQuestion::sole()->expert_id);
        $this->assertSame([], $this->to(501));
    }

    public function test_nobody_reachable_means_the_old_group_note(): void
    {
        $this->expert(501, 'Service', ['bot_chat_at' => null]);
        $this->answers = [$this->unknown('Q?', 'topic') + ['reply' => '']];
        $this->customerWrites(7001, 'Savol');

        $this->assertSame(0, ExpertQuestion::count());
        $this->assertCount(1, $this->to('-1003333333333'));
    }

    public function test_keep_it_after_choosing_a_transfer_lets_the_expert_answer(): void
    {
        $service = $this->expert(501, 'Service');
        $this->expert(502, 'Sales');
        $this->answers = [$this->unknown('Q?', 'topic')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();

        $this->press($service, "xq:{$q->id}:to:".ExpertDesk::code('Sales'));
        $this->press($service, "xq:{$q->id}:back");
        $this->answers = [['reply' => 'OK']];
        $this->expertWrites($service, ['text' => 'The answer']);

        $q->refresh();
        $this->assertSame('answered', $q->status);
        $this->assertSame('The answer', $q->answer);
        $this->assertSame(0, $q->transfers);
    }

    public function test_a_replaced_main_admin_loses_their_question(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $old = $this->expert(503, 'CEO', ['answers_questions' => false, 'is_main_admin' => true]);
        $this->answers = [$this->unknown('Q?', 'topic')];
        $this->customerWrites(7001, 'Savol');
        $this->assertSame($old->id, ExpertQuestion::sole()->expert_id);

        $new = TelegramUser::create(['telegram_id' => '504', 'bot_chat_at' => now()]);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $new), ['contact_type' => 'employee', 'employee_role' => 'CEO', 'is_main_admin' => '1']);

        $this->assertNotSame($old->id, ExpertQuestion::sole()->expert_id);
    }
}
