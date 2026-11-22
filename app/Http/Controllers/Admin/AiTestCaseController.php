<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiEmployee;
use App\Models\AiSandboxChat;
use App\Models\AiTestCase;
use App\Models\AiTestRun;
use App\Services\SalesAssistant\TestRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

use function Illuminate\Support\defer;

/** Test cases of the AI employees: scripted customers + checklists, run against a prompt version and graded. */
class AiTestCaseController extends Controller
{
    public function index()
    {
        $employees = AiEmployee::with('prompts')->orderBy('sort')->orderBy('id')->get();

        return view('admin.ai-team.test-cases.index', [
            'cases' => AiTestCase::with('employee')->orderByDesc('is_active')->orderBy('id')->get(),
            'employees' => $employees->map(fn (AiEmployee $e) => ['employee' => $e,
                'published' => TestRunner::summary($e, $e->publishedPrompt()), 'draft' => TestRunner::summary($e, $e->draftPrompt())]),
            'recent' => AiTestRun::with('testCase', 'employee')->latest('id')->limit(30)->get(),
        ]);
    }

    public function create(Request $request)
    {
        $case = new AiTestCase(['via' => 'bot', 'is_active' => true, 'turns' => [], 'criteria' => []]);
        if ($request->integer('sandbox') && ($chat = AiSandboxChat::where('user_id', $request->user()->id)->find($request->integer('sandbox')))) {
            $case->fill(['ai_employee_id' => $chat->ai_employee_id, 'via' => $chat->via,
                'turns' => collect($chat->messages ?? [])->where('role', 'user')->pluck('content')->filter()->values()->all()]);
        }

        return view('admin.ai-team.test-cases.form', ['case' => $case, 'employees' => AiEmployee::orderBy('sort')->get()]);
    }

    public function store(Request $request)
    {
        AiTestCase::create($this->validated($request));

        return redirect()->route('admin.ai-test-cases.index')->with('success', 'Test case added.');
    }

    public function edit(AiTestCase $aiTestCase)
    {
        return view('admin.ai-team.test-cases.form', ['case' => $aiTestCase, 'employees' => AiEmployee::orderBy('sort')->get()]);
    }

    public function update(Request $request, AiTestCase $aiTestCase)
    {
        $aiTestCase->update($this->validated($request));

        return redirect()->route('admin.ai-test-cases.index')->with('success', 'Test case saved. Run the tests again to grade prompts against it.');
    }

    public function destroy(AiTestCase $aiTestCase)
    {
        $aiTestCase->delete();

        return redirect()->route('admin.ai-test-cases.index')->with('success', 'Test case deleted.');
    }

    /** Queue the employee's cases against its draft or published prompt; they run after the response (and every minute). */
    public function run(Request $request, AiEmployee $aiEmployee, string $which, TestRunner $runner)
    {
        abort_unless(in_array($which, ['draft', 'published'], true), 404);
        $queued = $runner->queue($aiEmployee, $which, $request->user()->name);
        if ($queued) {
            defer(fn () => app(TestRunner::class)->processQueued());
        }

        return back()->with('success', $queued
            ? "{$queued} test case(s) queued for {$aiEmployee->name}'s {$which} prompt. Each takes about a minute; refresh to see the scores."
            : ($which === 'draft' && ! $aiEmployee->draftPrompt() ? 'There is no draft to test.' : 'Nothing new to run: these cases are already queued or have no active cases.'));
    }

    public function showRun(AiTestRun $run)
    {
        return view('admin.ai-team.test-cases.run', ['run' => $run->load('testCase', 'employee')]);
    }

    private function validated(Request $request): array
    {
        $lines = fn (?string $text) => array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text)), fn ($l) => $l !== ''));
        $request->merge(['turns' => $lines($request->input('turns_text')), 'criteria' => $lines($request->input('criteria_text'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'ai_employee_id' => ['nullable', Rule::exists('ai_employees', 'id')],
            'via' => ['required', Rule::in(['bot', 'assistant'])],
            'turns' => ['required', 'array', 'min:1', 'max:10'],
            'turns.*' => ['string', 'max:1000'],
            'criteria' => ['required', 'array', 'min:1', 'max:12'],
            'criteria.*' => ['string', 'max:300'],
            'is_active' => ['boolean'],
        ], ['turns.required' => 'Write at least one customer message.', 'criteria.required' => 'Write at least one checklist item.']);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
