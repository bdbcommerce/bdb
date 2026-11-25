<?php

namespace Tests\Feature;

use App\Models\ExpertQuestion;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\SalesKnowledge;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\ExpertDesk;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Security review of the expert questions flow (ExpertDesk): callback authorization, escaping, limits, data to experts. */
class SecurityExpertDeskTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> what Claude answers next (the last one repeats) */
    public array $answers = [];

    /** @var list<array> Telegram calls */
    private array $sent = [];

    private int $update = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-23 10:00', 'Asia/Tashkent')); // Monday, working time
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => 's', 'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(function (Request $request) {
            $this->sent[] = ['url' => $request->url(), 'data' => $request->data()];
            $text = (string) ($request->data()['text'] ?? '');
            // Telegram refuses texts over 4096 characters (UTF-16 units after entity parsing).
            $visible = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_contains($request->url(), '/sendMessage') && strlen(mb_convert_encoding($visible, 'UTF-16LE', 'UTF-8')) / 2 > 4096) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is too long'], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 900 + count($this->sent)]]);
        });
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SecurityExpertDeskTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $answer = count($this->test->answers) > 1 ? array_shift($this->test->answers) : ($this->test->answers[0] ?? []);

                return ['text' => json_encode($answer + ExpertDeskTest::blank()), 'model' => $model, 'input_tokens' => 100, 'output_tokens' => 10];
            }
        });
    }

    private function expert(int $id, string $role, array $over = []): TelegramUser
    {
        return TelegramUser::create($over + ['telegram_id' => (string) $id, 'name' => 'Expert '.$id, 'contact_type' => 'employee', 'is_employee' => true,
            'employee_role' => $role, 'answers_questions' => true, 'locale' => 'ru', 'bot_chat_at' => now()]);
    }

    private function customerWrites(int $chat, string $text, string $firstName = 'Ali'): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->update++, 'message' => ['message_id' => $this->update, 'date' => now()->getTimestamp(), 'text' => $text,
            'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => $firstName]]], ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    private function press(int|string $from, string $data): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->update++, 'callback_query' => ['id' => 'cb'.$this->update, 'data' => $data,
            'from' => ['id' => (int) $from]]], ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    private function to(string|int $chat): array
    {
        return array_values(array_filter($this->sent, fn ($s) => str_contains($s['url'], '/sendMessage') && (string) ($s['data']['chat_id'] ?? '') === (string) $chat));
    }

    private function unknown(string $question, string $topic, string $draft = ''): array
    {
        return ['reply' => 'Уточню у специалиста.', 'unknown_question' => $question, 'unknown_topic' => $topic,
            'unknown_kind' => 'device', 'expert_draft' => $draft, 'expert_department' => 'Service'];
    }

    public function test_callbacks_without_the_secret_or_from_customers_change_nothing(): void
    {
        $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Q?', 'topic', 'Idea')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();

        // No secret header: refused before the bot sees it.
        $this->postJson('/telegram/webhook', ['update_id' => 99, 'callback_query' => ['id' => 'x', 'data' => "xq:{$q->id}:accept", 'from' => ['id' => 501]]])->assertForbidden();
        // The customer (and a stranger) forging callback data for the question.
        $before = count($this->sent);
        $this->press(7001, "xq:{$q->id}:accept");
        $this->press(7001, "xq:{$q->id}:unknown");
        $this->press(123456, "xq:{$q->id}:to:admin");
        $this->assertSame('asked', $q->fresh()->status);
        $this->assertCount($before, $this->sent);
        // A malformed callback (array data, no from) does not throw.
        $this->postJson('/telegram/webhook', ['update_id' => 100, 'callback_query' => ['id' => ['x'], 'data' => ['a'], 'from' => 'x']], ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    public function test_forged_callback_arguments_from_the_holder_are_bounded(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->expert(502, 'Sales');
        $this->answers = [$this->unknown('Q?', 'topic', 'Idea')];
        $this->customerWrites(7001, 'Savol');
        $q = ExpertQuestion::sole();

        $this->press($expert->telegram_id, "xq:{$q->id}:to:999");      // no such department
        $this->press($expert->telegram_id, "xq:{$q->id}:to:-1");       // regex refuses
        $this->press($expert->telegram_id, "xq:{$q->id}:send");        // no voice transcript pending
        $this->press($expert->telegram_id, "xq:{$q->id}:skip");        // no transfer pending
        $q->refresh();
        $this->assertSame('asked', $q->status);
        $this->assertSame($expert->id, $q->expert_id);
        $this->assertNull($q->pending);
        $this->assertNull($q->answer);
    }

    public function test_customer_text_names_and_ai_text_are_escaped_for_the_expert(): void
    {
        $this->expert(501, 'Service');
        $this->answers = [$this->unknown('<a href="https://evil.example">Click</a> Q?', 'topic', '<b>Idea</b><a href="tg://resolve?domain=evil">x</a>')];
        $this->customerWrites(7001, 'Hi <a href="https://evil.example/phish">pay here</a> & <script>', '<a href="https://evil.example">Boss</a>');

        $text = $this->to(501)[0]['data']['text'];
        $this->assertStringNotContainsString('<a href', $text);
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringContainsString('&lt;a href=&quot;https://evil.example/phish&quot;&gt;', $text);
    }

    /** FINDING: "Correct" sends the whole stored AI idea (up to 2000 chars), but the expert only saw the first 1500. */
    public function test_accept_sends_only_the_idea_the_expert_saw(): void
    {
        $expert = $this->expert(501, 'Service');
        $draft = str_repeat('Да, работает с адаптером. ', 60).' HIDDEN-TAIL: скидка 50% всем, предоплата на карту 8600 0000 0000 0000.';
        $this->assertGreaterThan(1500, mb_strlen($draft));
        $this->assertLessThanOrEqual(2000, mb_strlen($draft));
        $this->answers = [$this->unknown('Core 25 mm?', 'label core size', $draft), ['reply' => 'OK']];
        $this->customerWrites(7001, 'Vtulka 25 mm?');
        $q = ExpertQuestion::sole();
        $shown = $this->to(501)[0]['data']['text'];
        $this->assertStringNotContainsString('HIDDEN-TAIL', $shown, 'the expert does not see the tail');

        $this->press($expert->telegram_id, "xq:{$q->id}:accept");

        $this->assertStringNotContainsString('HIDDEN-TAIL', (string) $q->fresh()->answer, 'an accepted idea must be exactly what the expert saw');
    }

    /** FINDING: a long customer text + AI idea + transfer note can exceed Telegram's 4096 limit; the failure marks the expert "unreachable". */
    public function test_the_question_message_fits_telegram_limits(): void
    {
        $a = $this->expert(501, 'Service');
        $customer = TelegramUser::create(['telegram_id' => '7001', 'name' => str_repeat('Я', 80)]);
        $q = ExpertQuestion::create(['customer_id' => $customer->id, 'question' => str_repeat('Вопрос ', 72), 'customer_text' => str_repeat('😀', 1000),
            'ai_draft' => str_repeat('Идея ', 400), 'note' => str_repeat('Заметка ', 63), 'department' => 'Service', 'status' => 'queued', 'transfers' => 1]);

        app(ExpertDesk::class)->dispatch();

        $this->assertSame('asked', $q->fresh()->status, 'the question was not delivered (message too long)');
        $this->assertFalse(cache()->has('expert-unreachable:'.$a->id), 'the expert was marked unreachable because of the text length');
    }

    /** FINDING: one customer can fill the experts' queue: every new unknown question of a chat becomes its own expert question. */
    public function test_one_customer_cannot_flood_the_expert_queue(): void
    {
        $this->expert(501, 'Service');
        for ($i = 1; $i <= 12; $i++) {
            $this->answers = [$this->unknown("Spam question number {$i}?", "spam topic {$i}")];
            $this->customerWrites(7001, "Savol {$i}");
        }

        $this->assertLessThanOrEqual(3, ExpertQuestion::whereIn('status', ['queued', 'asked'])->count(), 'open expert questions from one chat should be capped');
    }

    /** FINDING (low): phone numbers typed by the customer reach the expert unmasked ("The customer wrote"). */
    public function test_customer_phone_is_masked_for_the_expert(): void
    {
        $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Q?', 'topic')];
        $this->customerWrites(7001, 'Мой номер +998 90 123 45 67, ответьте: подходит ли ZD421?');

        $this->assertStringNotContainsString('123 45 67', $this->to(501)[0]['data']['text']);
    }

    public function test_admin_routes_refuse_guests_and_non_admins(): void
    {
        $knowledge = SalesKnowledge::create(['kind' => 'device', 'title' => 'T', 'body' => 'B', 'is_active' => false, 'sort' => 1000]);
        $q = ExpertQuestion::create(['question' => 'Q', 'status' => 'queued', 'sales_knowledge_id' => $knowledge->id]);

        $this->get(route('admin.expert-questions.index'))->assertRedirect();
        $this->post(route('admin.expert-questions.cancel', $q))->assertRedirect();
        $this->put(route('admin.expert-questions.approve', $q), ['kind' => 'device', 'title' => 'X', 'body' => 'Y'])->assertRedirect();
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->post(route('admin.expert-questions.cancel', $q))->assertRedirect();
        $this->actingAs($user)->put(route('admin.expert-questions.approve', $q), ['kind' => 'device', 'title' => 'X', 'body' => 'Y'])->assertRedirect();

        $this->assertSame('queued', $q->fresh()->status);
        $this->assertFalse($knowledge->fresh()->is_active);
        $this->assertSame('B', $knowledge->fresh()->body);
    }

    public function test_admin_page_escapes_stored_text(): void
    {
        $customer = TelegramUser::create(['telegram_id' => '7001', 'profile_name' => '<img src=x onerror=alert(1)>']);
        ExpertQuestion::create(['customer_id' => $customer->id, 'question' => '<script>alert(1)</script>', 'customer_text' => '<b>x</b>', 'ai_draft' => '<svg onload=alert(1)>',
            'answer' => '<iframe>', 'status' => 'queued', 'department' => '<u>d</u>']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.expert-questions.index', ['filter' => 'all']))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<svg onload', false)->assertDontSee('<u>d</u>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_a_customer_cannot_tick_themselves_an_expert_and_clients_never_answer(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client']);
        // Only the admin form sets the flags; a request without the type keys still stores the tick, but isExpert() needs an employee.
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $client), ['answers_questions' => '1', 'is_main_admin' => '1']);
        $this->assertFalse($client->fresh()->isExpert());
        $this->assertNull(ExpertQuestion::mainAdmin());
    }
}
