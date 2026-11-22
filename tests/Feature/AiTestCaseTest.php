<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiSandboxChat;
use App\Models\AiTestCase;
use App\Models\AiTestRun;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistantAi;
use App\Services\SalesAssistant\TestRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Test cases: scripted customers played against a prompt version, graded by Claude Haiku; the publish gate. */
class AiTestCaseTest extends TestCase
{
    use RefreshDatabase;

    public array $calls = [];

    /** Whether the judge passes each item. */
    public bool $judgePasses = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-ant-test', 'services.telegram.bot_token' => '123:abc']);
        InvoiceParserSetting::current();
        Http::fake();
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private AiTestCaseTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('model', 'system', 'data');
                if (isset($schema['properties']['results'])) { // the grader
                    $items = preg_match_all('/^\d+\. /m', explode('# CHECKLIST', $data)[1] ?? '');
                    $results = array_map(fn ($i) => ['item' => $i, 'pass' => $this->test->judgePasses || $i === 1, 'reason' => "Item {$i} checked."], range(1, $items));

                    return ['text' => json_encode(['results' => $results]), 'model' => $model, 'input_tokens' => 500, 'output_tokens' => 60];
                }

                return ['text' => json_encode(['reply' => 'Qanday biznes uchun kerak?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                    'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'hand_to' => '']),
                    'model' => 'claude-opus-5-5', 'input_tokens' => 2000, 'output_tokens' => 50];
            }
        });
        $this->withoutDefer(); // the queued runs start right after the request (here: at once)
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_starter_cases_runs_grading_and_the_publish_gate(): void
    {
        $admin = $this->admin();
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $this->assertSame(6, AiTestCase::count());
        $this->assertSame(4, AiTestCase::query()->for($malika)->count(), 'her 3 cases and the one for every employee');

        $this->actingAs($admin)->post(route('admin.ai-employees.draft', $malika), ['prompt' => 'Ask about the business first.']);
        $this->actingAs($admin)->post(route('admin.ai-employees.publish', $malika))->assertSessionHasErrors(['employee' => 'Run the test cases on draft v2 first (0 of 4 done).']);

        // The published version is graded at 100%, the draft only passes item 1 of each case.
        $this->actingAs($admin)->post(route('admin.ai-employees.tests', [$malika, 'published']))->assertSessionHas('success', fn ($m) => str_contains($m, '4 test case(s) queued'));
        $this->artisan('ai:run-tests')->assertSuccessful(); // nothing left
        $this->assertSame(4, AiTestRun::where('status', 'done')->where('score', 100)->count());
        $turns = collect(AiTestCase::query()->for($malika)->get())->sum(fn ($c) => count($c->turns));
        $this->assertCount($turns + 4, $this->calls, 'one call per customer turn, one grading per case');
        $this->assertStringStartsWith('You are Malika', $this->calls[0]['system']);

        $this->judgePasses = false;
        $this->actingAs($admin)->post(route('admin.ai-employees.tests', [$malika, 'draft']));
        $this->artisan('ai:run-tests');
        $summary = TestRunner::summary($malika, $malika->draftPrompt());
        $this->assertSame(4, $summary['done']);
        $this->assertLessThan(100, $summary['score']);
        $this->actingAs($admin)->post(route('admin.ai-employees.publish', $malika))->assertSessionHasErrors('employee');
        $this->assertSame(1, $malika->publishedPrompt()->version);
        $this->actingAs($admin)->get(route('admin.ai-employees.edit', $malika))->assertOk()->assertSee('Publishing is blocked')->assertSee('100%');

        // A better draft (edited → its earlier runs no longer count), graded 100% → may be published.
        $this->judgePasses = true;
        $this->actingAs($admin)->post(route('admin.ai-employees.draft', $malika), ['prompt' => 'Ask about the business first. Then one question at a time.']);
        $this->assertSame(0, TestRunner::summary($malika, $malika->draftPrompt())['done'], 'an edited draft needs a new run');
        $this->actingAs($admin)->post(route('admin.ai-employees.tests', [$malika, 'draft']));
        $this->artisan('ai:run-tests');
        $this->actingAs($admin)->post(route('admin.ai-employees.publish', $malika))->assertSessionHasNoErrors();
        $this->assertSame(2, $malika->publishedPrompt()->version);

        $run = AiTestRun::where('status', 'done')->latest('id')->first();
        $this->actingAs($admin)->get(route('admin.ai-test-runs.show', $run))->assertOk()->assertSee('Item 1 checked.')->assertSee('Qanday biznes uchun kerak?');
        $this->actingAs($admin)->get(route('admin.ai-test-cases.index'))->assertOk()->assertSee('Pharmacy, 2 cash desks')->assertSee('Recent runs');
        Http::assertNothingSent();
    }

    public function test_a_run_that_cannot_reach_claude_fails_cleanly_and_queue_is_not_doubled(): void
    {
        $javohir = AiEmployee::where('name', 'Javohir')->sole();
        $runner = app(TestRunner::class);
        $this->assertSame(2, $runner->queue($javohir, 'published', 'Aziz'), 'his case and the one for everyone');
        $this->assertSame(0, $runner->queue($javohir, 'published', 'Aziz'), 'already queued');
        $this->assertSame(0, $runner->queue($javohir, 'draft', 'Aziz'), 'no draft');

        config(['services.anthropic.key' => null]);
        $this->artisan('ai:run-tests');
        $this->assertSame(['failed', 'failed'], AiTestRun::pluck('status')->all());
        $this->assertStringContainsString('ANTHROPIC_API_KEY', AiTestRun::first()->error);
    }

    public function test_case_crud_and_save_from_the_sandbox(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.ai-test-cases.store'), ['name' => 'Empty', 'via' => 'bot', 'turns_text' => "  \n", 'criteria_text' => 'x'])
            ->assertSessionHasErrors('turns');
        $this->actingAs($admin)->post(route('admin.ai-test-cases.store'), ['name' => 'Delivery to Samarkand', 'via' => 'bot', 'is_active' => '1',
            'turns_text' => "Samarqandga yetkazasizmi?\r\nQachon?", 'criteria_text' => "Never promises a date\nAsks the city"])->assertRedirect();
        $case = AiTestCase::latest('id')->first();
        $this->assertSame(['Samarqandga yetkazasizmi?', 'Qachon?'], $case->turns);
        $this->assertNull($case->ai_employee_id);

        $chat = AiSandboxChat::create(['user_id' => $admin->id, 'ai_employee_id' => AiEmployee::first()->id, 'via' => 'assistant',
            'messages' => [['role' => 'user', 'content' => 'Salom'], ['role' => 'assistant', 'content' => 'Assalomu alaykum'], ['role' => 'user', 'content' => 'Printer kerak']]]);
        $this->actingAs($admin)->get(route('admin.ai-test-cases.create', ['sandbox' => $chat->id]))->assertOk()
            ->assertSee("Salom\nPrinter kerak", false)->assertSee('value="assistant" selected', false);
        $other = $this->admin();
        $this->actingAs($other)->get(route('admin.ai-test-cases.create', ['sandbox' => $chat->id]))->assertOk()->assertDontSee('Printer kerak');

        $this->actingAs($admin)->delete(route('admin.ai-test-cases.destroy', $case))->assertRedirect();
        $this->assertSame(6, AiTestCase::count());
        auth()->logout();
        $this->get(route('admin.ai-test-cases.index'))->assertRedirect();
    }
}
