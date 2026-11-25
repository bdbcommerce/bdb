<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ExpertQuestion;
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

/** Expert questions: edge cases (QA). */
class ExpertDeskEdgeTest extends TestCase
{
    use RefreshDatabase;

    public array $calls = [];

    public array $answers = [];

    private array $sent = [];

    /** chat IDs whose sendMessage fails (bot blocked / never started) */
    public array $failChats = [];

    private int $update = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-23 10:00', 'Asia/Tashkent'));
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => 's', 'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(function (Request $request) {
            $this->sent[] = ['url' => $request->url(), 'data' => $request->data()];
            if (str_contains($request->url(), '/sendMessage') && in_array((string) ($request->data()['chat_id'] ?? ''), $this->failChats, true)) {
                return Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot can\'t initiate conversation with a user'], 403);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 900 + count($this->sent)]]);
        });
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private ExpertDeskEdgeTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('system', 'data');
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

    private function to(string|int $chat): array
    {
        return array_values(array_filter($this->sent, fn ($s) => str_contains($s['url'], '/sendMessage') && (string) ($s['data']['chat_id'] ?? '') === (string) $chat));
    }

    private function unknown(string $question, string $topic, string $draft = '', string $department = 'Service'): array
    {
        return ['reply' => 'Уточню у специалиста.', 'unknown_question' => $question, 'unknown_topic' => $topic,
            'unknown_kind' => 'device', 'expert_draft' => $draft, 'expert_department' => $department];
    }

    public function test_working_minutes_over_a_weekend(): void
    {
        $s = SalesAssistantSetting::current();
        $s->update(['work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00']);
        $from = Carbon::parse('2026-11-27 17:30', 'Asia/Tashkent'); // Friday
        $to = Carbon::parse('2026-11-30 09:30', 'Asia/Tashkent');   // Monday
        $this->assertSame(60, $s->fresh()->workingMinutesBetween($from, $to));
        $this->assertSame(0, $s->fresh()->workingMinutesBetween(Carbon::parse('2026-11-28 10:00', 'Asia/Tashkent'), Carbon::parse('2026-11-29 17:00', 'Asia/Tashkent')));
        $this->assertSame(9 * 60 + 60, $s->fresh()->workingMinutesBetween(Carbon::parse('2026-11-23 09:00', 'Asia/Tashkent'), Carbon::parse('2026-11-24 10:00', 'Asia/Tashkent')));
        // UTC input
        $this->assertSame(30, $s->fresh()->workingMinutesBetween(Carbon::parse('2026-11-23 04:00', 'UTC'), Carbon::parse('2026-11-23 04:30', 'UTC')));
    }

    public function test_admin_page_filters(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Core 25 mm?', 'label core size', 'Yes.')];
        $this->customerWrites(7001, 'Vtulka 25 mm?');
        $this->customerWrites(7002, 'Vtulka 25 mm ham?');
        $this->answers = [$this->unknown('Other <b>x</b>?', 'other topic')];
        $this->customerWrites(7003, 'Boshqa');
        $this->answers = [['reply' => 'ok']];
        $this->press($expert, 'xq:'.ExpertQuestion::whereNull('parent_id')->orderBy('id')->first()->id.':accept');
        $admin = User::factory()->create(['is_admin' => true]);
        foreach (['open', 'review', 'answered', 'unknown', 'all', 'bogus', ''] as $f) {
            $r = $this->actingAs($admin)->get(route('admin.expert-questions.index', ['filter' => $f]));
            $r->assertOk();
            $this->assertStringNotContainsString('Other <b>x</b>?', $r->getContent());
        }
    }

    public function test_expert_without_question_writing_normally_goes_to_old_flow(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->expertWrites($expert, ['text' => 'Hello bot']);
        $this->assertSame(1, ChatConversation::where('external_id', '501')->count(), 'recorded as before');
        $this->assertSame(0, count($this->calls));
    }

    public function test_unticked_expert_presses_a_button(): void
    {
        $expert = $this->expert(501, 'Service');
        $this->answers = [$this->unknown('Core 25 mm?', 'label core size', 'Yes.')];
        $this->customerWrites(7001, 'Vtulka 25 mm?');
        $q = ExpertQuestion::sole();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('admin.telegram-users.update', $expert), ['contact_type' => 'employee', 'employee_role' => 'Service', 'answers_questions' => '0', 'is_main_admin' => '0']);
        $this->assertSame('queued', $q->fresh()->status);
        $this->press($expert->fresh(), "xq:{$q->id}:accept");
        $this->assertSame('queued', $q->fresh()->status);
        $this->expertWrites($expert->fresh(), ['text' => 'Yes']);
        $this->assertSame('queued', $q->fresh()->status);
    }
}
