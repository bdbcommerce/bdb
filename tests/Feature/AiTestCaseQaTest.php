<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiTestCase;
use App\Models\AiTestRun;
use App\Models\InvoiceParserSetting;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use App\Services\SalesAssistant\TestRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA of AI test cases (commit 845dbdc): gate edge cases, judge output, queue/lock, validation, escaping, access. */
class AiTestCaseQaTest extends TestCase
{
    use RefreshDatabase;

    /** Raw text the judge returns; null = a well-formed answer for every checklist item. */
    public ?string $judgeText = null;

    public string $judgeReason = 'Item checked.';

    public string $reply = 'Qanday biznes uchun kerak?';

    public int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-ant-test', 'services.telegram.bot_token' => '123:abc']);
        InvoiceParserSetting::current();
        Http::fake();
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private AiTestCaseQaTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls++;
                if (isset($schema['properties']['results'])) {
                    if ($this->test->judgeText !== null) {
                        return ['text' => $this->test->judgeText, 'model' => $model, 'input_tokens' => 10, 'output_tokens' => 10];
                    }
                    $items = preg_match_all('/^\d+\. /m', explode('# CHECKLIST', $data)[1] ?? '');
                    $results = array_map(fn ($i) => ['item' => $i, 'pass' => true, 'reason' => $this->test->judgeReason], range(1, max(1, $items)));

                    return ['text' => json_encode(['results' => $results]), 'model' => $model, 'input_tokens' => 10, 'output_tokens' => 10];
                }

                return ['text' => json_encode(['reply' => $this->test->reply, 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                    'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'hand_to' => '']),
                    'model' => 'claude-opus-5-5', 'input_tokens' => 100, 'output_tokens' => 10];
            }
        });
        $this->withoutDefer();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function malikaWithGradedDraft(): AiEmployee
    {
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $this->actingAs($this->admin())->post(route('admin.ai-employees.draft', $malika), ['prompt' => 'Ask about the business first.']);
        app(TestRunner::class)->queue($malika, 'draft', 'QA');
        app(TestRunner::class)->processQueued();

        return $malika;
    }

    // ---- Defects (these fail on 845dbdc) ----

    public function test_malformed_judge_json_is_a_failed_run_not_a_zero_score(): void
    {
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $this->judgeText = 'Sorry, I cannot grade this.';
        app(TestRunner::class)->queue($malika, 'published', 'QA');
        app(TestRunner::class)->processQueued();

        $this->assertSame([], AiTestRun::where('status', 'done')->pluck('score')->all(),
            'an unusable grading answer must not be recorded as a finished 0% run (it lowers the published bar so any draft passes the gate)');
    }

    public function test_deleting_an_employee_does_not_turn_its_cases_into_cases_for_everyone(): void
    {
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $javohir = AiEmployee::where('name', 'Javohir')->sole();
        $before = AiTestCase::query()->for($malika)->count();
        $this->actingAs($this->admin())->delete(route('admin.ai-employees.destroy', $javohir))->assertRedirect();
        $this->assertModelMissing($javohir);

        $this->assertSame($before, AiTestCase::query()->for($malika)->count(),
            "Javohir's case is now ai_employee_id = null = 'Every employee' and gates Malika's publish");
    }

    public function test_editing_a_case_invalidates_runs_graded_on_the_old_script(): void
    {
        $malika = $this->malikaWithGradedDraft();
        $this->assertNull(TestRunner::gate($malika, $malika->draftPrompt()));

        $case = AiTestCase::where('ai_employee_id', $malika->id)->first();
        $this->actingAs($this->admin())->put(route('admin.ai-test-cases.update', $case), ['name' => $case->name, 'ai_employee_id' => $malika->id, 'via' => 'bot',
            'is_active' => '1', 'turns_text' => 'Completely different customer', 'criteria_text' => "New item A\nNew item B"])->assertRedirect();

        $this->assertNotNull(TestRunner::gate($malika, $malika->draftPrompt()->fresh()),
            'the draft was never graded on the edited case, yet publishing is allowed');
    }

    public function test_migration_seed_does_not_make_cases_global_when_an_employee_name_is_missing(): void
    {
        DB::table('ai_test_cases')->delete();
        DB::table('ai_employees')->where('name', 'Malika')->update(['name' => 'Madina']);
        (require database_path('migrations/2026_11_23_130000_create_ai_test_tables.php'))->up();

        $this->assertSame(1, AiTestCase::whereNull('ai_employee_id')->count(),
            "Malika's 3 starter cases were seeded as 'Every employee' because her name was not found");
    }

    // ---- Checks that pass ----

    public function test_judge_missing_or_garbage_items_fail_only_those_items(): void
    {
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $this->judgeText = json_encode(['results' => [['item' => 1, 'pass' => true, 'reason' => 'ok'], 'junk', ['item' => 99, 'pass' => true, 'reason' => 'x'],
            ['item' => 2, 'pass' => 'true', 'reason' => ['array']]]]);
        $case = AiTestCase::where('name', 'Asks the price right away')->sole(); // 3 items
        AiTestCase::where('id', '!=', $case->id)->update(['is_active' => false]);
        app(TestRunner::class)->queue($malika, 'published', 'QA');
        app(TestRunner::class)->processQueued();

        $run = AiTestRun::sole();
        $this->assertSame('done', $run->status);
        $this->assertSame(33, $run->score);
        $this->assertSame([true, false, false], array_column($run->results, 'pass'));
        $this->assertSame('Not graded.', $run->results[1]['reason']);
        $this->assertSame('Not graded.', $run->results[2]['reason']);
    }

    public function test_lock_stuck_runs_and_daily_limit(): void
    {
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $runner = app(TestRunner::class);
        $this->assertSame(4, $runner->queue($malika, 'published', 'QA'));

        $lock = Cache::lock('ai-test-runs', 60);
        $this->assertTrue($lock->get());
        $this->assertSame(0, $runner->processQueued());
        $this->assertSame(4, AiTestRun::where('status', 'queued')->count());
        $lock->release();

        // A recent "running" run is left alone, an old one is retried.
        $ids = AiTestRun::orderBy('id')->pluck('id');
        AiTestRun::whereKey($ids[0])->update(['status' => 'running']);
        DB::table('ai_test_runs')->where('id', $ids[0])->update(['updated_at' => now()->subMinutes(20)]);
        AiTestRun::whereKey($ids[1])->update(['status' => 'running']);
        $this->assertSame(0, $runner->queue($malika, 'published', 'QA'), 'running/queued runs are not doubled');
        $runner->processQueued();
        $this->assertSame('failed', AiTestRun::find($ids[0])->status, 'a run whose worker stopped fails (no endless paid retries)');
        $this->assertSame('running', AiTestRun::find($ids[1])->status);

        // The daily sandbox/test limit stops a run with a readable error.
        Cache::put('ai-sandbox-turns:'.now()->format('Y-m-d'), SalesAssistant::SANDBOX_DAILY, now()->addDay());
        $runner->queue(AiEmployee::where('name', 'Dilshod')->sole(), 'published', 'QA');
        $runner->processQueued();
        $failed = AiTestRun::where('status', 'failed')->get();
        $this->assertNotEmpty($failed);
        $this->assertStringContainsString('limit', $failed->last()->error);
    }

    public function test_gate_with_inactive_and_deleted_cases_and_unpublished_versions(): void
    {
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $this->actingAs($admin = $this->admin())->post(route('admin.ai-employees.draft', $malika), ['prompt' => 'Draft text.']);
        $draft = $malika->draftPrompt();
        $this->assertStringContainsString('0 of 4', TestRunner::gate($malika, $draft));

        // Deactivated cases no longer count.
        AiTestCase::query()->for($malika)->get()->each->update(['is_active' => false]);
        $this->assertNull(TestRunner::gate($malika, $draft), 'no active cases: nothing to gate');

        AiTestCase::query()->where('ai_employee_id', $malika->id)->first()->update(['is_active' => true]);
        $this->assertStringContainsString('0 of 1', TestRunner::gate($malika, $draft));
        app(TestRunner::class)->queue($malika, 'draft', 'QA');
        app(TestRunner::class)->processQueued();
        $this->assertNull(TestRunner::gate($malika, $draft), 'published has no runs: graded draft may be published');

        // Deleting a case deletes its runs.
        $case = AiTestCase::query()->for($malika)->sole();
        $runs = AiTestRun::where('ai_test_case_id', $case->id)->count();
        $this->assertGreaterThan(0, $runs);
        $this->actingAs($admin)->delete(route('admin.ai-test-cases.destroy', $case))->assertRedirect();
        $this->assertSame(0, AiTestRun::where('ai_test_case_id', $case->id)->count());

        // A run whose prompt changed before it ran fails cleanly.
        AiTestCase::query()->where('ai_employee_id', $malika->id)->update(['is_active' => true]);
        app(TestRunner::class)->queue($malika, 'draft', 'QA');
        $draft->update(['prompt' => 'Edited meanwhile.']);
        app(TestRunner::class)->processQueued();
        $this->assertStringContainsString('changed before the run', AiTestRun::where('status', 'failed')->latest('id')->first()->error);
    }

    public function test_validation_limits_crlf_unicode_and_old_input(): void
    {
        $admin = $this->admin();
        $base = ['name' => 'Case', 'via' => 'bot', 'turns_text' => 'Salom', 'criteria_text' => 'Polite'];
        $cases = [
            'turns' => ['turns_text' => implode("\n", array_fill(0, 11, 'x'))],
            'turns.0' => ['turns_text' => str_repeat('a', 1001)],
            'criteria' => ['criteria_text' => implode("\r\n", array_fill(0, 13, 'y'))],
            'criteria.0' => ['criteria_text' => str_repeat('b', 301)],
            'name' => ['name' => str_repeat('n', 151)],
            'via' => ['via' => 'email'],
            'ai_employee_id' => ['ai_employee_id' => 99999],
        ];
        foreach ($cases as $field => $override) {
            $this->actingAs($admin)->from(route('admin.ai-test-cases.create'))->post(route('admin.ai-test-cases.store'), $override + $base)
                ->assertRedirect(route('admin.ai-test-cases.create'))->assertSessionHasErrors($field);
        }
        $this->assertSame(6, AiTestCase::count());

        // Old input is kept and errors on a single line are shown.
        $this->actingAs($admin)->from(route('admin.ai-test-cases.create'))->followingRedirects()
            ->post(route('admin.ai-test-cases.store'), ['turns_text' => "Birinchi o‘rin\n".str_repeat('z', 1001)] + $base)
            ->assertOk()->assertSee('Birinchi o‘rin')->assertSee('turns.1', false);

        $this->actingAs($admin)->post(route('admin.ai-test-cases.store'), ['name' => 'Ўзбекча / Русский', 'via' => 'assistant',
            'turns_text' => "Здравствуйте\r\n\r\n  Qog‘oz kerak  \r", 'criteria_text' => "Отвечает по-русски\r\n"])->assertRedirect(route('admin.ai-test-cases.index'));
        $case = AiTestCase::latest('id')->first();
        $this->assertSame(['Здравствуйте', 'Qog‘oz kerak'], $case->turns);
        $this->assertSame(['Отвечает по-русски'], $case->criteria);
        $this->assertFalse($case->is_active, 'unchecked box = inactive');
    }

    public function test_user_text_is_escaped_everywhere(): void
    {
        $admin = $this->admin();
        $xss = '<script>alert(1)</script>';
        $this->actingAs($admin)->post(route('admin.ai-test-cases.store'), ['name' => "N{$xss}", 'via' => 'bot', 'is_active' => '1',
            'ai_employee_id' => AiEmployee::where('name', 'Malika')->value('id'),
            'turns_text' => "T{$xss}", 'criteria_text' => "C{$xss}"]);
        $this->judgeReason = "R{$xss}";
        $this->reply = "A{$xss}";
        $malika = AiEmployee::where('name', 'Malika')->sole();
        AiTestCase::where('name', '!=', "N{$xss}")->update(['is_active' => false]);
        app(TestRunner::class)->queue($malika, 'published', 'QA');
        app(TestRunner::class)->processQueued();
        $run = AiTestRun::sole();
        $this->assertSame('done', $run->status);

        foreach ([route('admin.ai-test-cases.index'), route('admin.ai-test-cases.edit', $run->ai_test_case_id), route('admin.ai-test-runs.show', $run),
            route('admin.ai-employees.edit', $malika)] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee($xss, false);
        }
        $this->actingAs($admin)->get(route('admin.ai-test-runs.show', $run))->assertSee('R&lt;script&gt;', false)->assertSee('A&lt;script&gt;', false);

        $run->update(['status' => 'failed', 'error' => "E{$xss}"]);
        $this->actingAs($admin)->get(route('admin.ai-test-runs.show', $run))->assertOk()->assertDontSee($xss, false)->assertSee('E&lt;script&gt;', false);
    }

    public function test_access_control_and_sidebar_state(): void
    {
        $case = AiTestCase::first();
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $run = AiTestRun::create(['ai_test_case_id' => $case->id, 'ai_employee_id' => $malika->id, 'ai_employee_prompt_id' => $malika->publishedPrompt()->id,
            'prompt_version' => 1, 'prompt_hash' => md5($malika->publishedPrompt()->prompt), 'status' => 'failed', 'error' => 'x']);
        $gets = [route('admin.ai-test-cases.index'), route('admin.ai-test-cases.create'), route('admin.ai-test-cases.edit', $case), route('admin.ai-test-runs.show', $run)];

        foreach ($gets as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
        $user = User::factory()->create(['is_admin' => false]);
        foreach ($gets as $url) {
            $this->assertNotSame(200, $this->actingAs($user)->get($url)->status(), $url);
        }
        $this->actingAs($user)->post(route('admin.ai-test-cases.store'), ['name' => 'x', 'via' => 'bot', 'turns_text' => 'a', 'criteria_text' => 'b']);
        $this->actingAs($user)->delete(route('admin.ai-test-cases.destroy', $case));
        $this->actingAs($user)->post(route('admin.ai-employees.tests', [$malika, 'published']));
        $this->assertSame(6, AiTestCase::count());
        $this->assertSame(1, AiTestRun::count());
        auth()->logout();

        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/ai-employees/'.$malika->id.'/tests/everything')->assertNotFound();
        foreach ($gets as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#aria-label="Breadcrumb">\s*<p[^>]*>.*?/</span>\s*Test cases\s*</p>#s', $html, $url);
        }
        $this->actingAs($admin)->get('/admin/ai-team/test-cases')->assertNotFound();
        Http::assertNothingSent();
    }
}
