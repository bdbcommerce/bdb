<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiEmployee;
use App\Models\AiEmployeePrompt;
use App\Models\SalesAssistantSetting;
use App\Support\AiEmployeeTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** AI employees: cards (name, role, temperament), versioned role prompts (draft → publish) and the platform rules. */
class AiEmployeeController extends Controller
{
    public function index()
    {
        return view('admin.ai-team.employees.index', [
            'employees' => AiEmployee::with('prompts')->orderBy('sort')->orderBy('id')->get(),
            'rules' => AiEmployee::platformRules(),
            'customRules' => filled(SalesAssistantSetting::current()->platform_rules),
            'templates' => collect(AiEmployeeTemplates::all())->reject(fn ($t) => AiEmployee::where('name', $t['name'])->exists()),
        ]);
    }

    public function create(Request $request)
    {
        $template = AiEmployeeTemplates::all()[(string) $request->query('template')] ?? null;

        return view('admin.ai-team.employees.form', ['employee' => new AiEmployee($template ? array_merge($template, ['status' => 'draft']) : [
            'kind' => 'sales', 'status' => 'draft', 'channels' => ['bot', 'business'], 'scales' => ['formality' => 3, 'length' => 2, 'initiative' => 3, 'emoji' => 1],
        ]), 'prompt' => $template['prompt'] ?? '']);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $prompt = $request->validate(['prompt' => ['required', 'string', 'max:20000', 'not_regex:/\[TO FILL/i']])['prompt'];
        $employee = DB::transaction(function () use ($data, $prompt, $request) {
            $employee = AiEmployee::create($data + ['sort' => (int) AiEmployee::max('sort') + 1]);
            $employee->prompts()->create(['version' => 1, 'prompt' => $prompt, 'note' => 'First version', 'status' => 'draft', 'author' => $request->user()->name]);

            return $employee;
        });

        return redirect()->route('admin.ai-employees.edit', $employee)->with('success', 'AI employee added as a draft. Publish the prompt when it is ready.');
    }

    public function edit(AiEmployee $aiEmployee)
    {
        $draft = $aiEmployee->draftPrompt();
        $published = $aiEmployee->publishedPrompt();

        return view('admin.ai-team.employees.form', [
            'employee' => $aiEmployee,
            'versions' => $aiEmployee->prompts()->get(),
            'draft' => $draft,
            'published' => $published,
            'prompt' => $draft?->prompt ?? $published?->prompt ?? '',
            'preview' => $aiEmployee->promptLayers($draft?->prompt),
            'previewOf' => $draft ? 'draft v'.$draft->version : ($published ? 'published v'.$published->version : null),
        ]);
    }

    public function update(Request $request, AiEmployee $aiEmployee)
    {
        $aiEmployee->update($this->validated($request, $aiEmployee));

        return back()->with('success', 'Card saved.');
    }

    public function destroy(AiEmployee $aiEmployee)
    {
        if ($aiEmployee->isLive()) {
            return back()->withErrors(['employee' => 'A live AI employee cannot be deleted — switch it to Draft first.']);
        }
        $aiEmployee->delete();

        return redirect()->route('admin.ai-employees.index')->with('success', 'AI employee deleted.');
    }

    /** Save the role prompt as the draft (one draft at a time; a new version number when there is none). */
    public function saveDraft(Request $request, AiEmployee $aiEmployee)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:20000', 'not_regex:/\[TO FILL/i'],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['prompt.not_regex' => 'Replace the [TO FILL: …] placeholders first.']);
        $data['prompt'] = str_replace(["\r\n", "\r"], "\n", $data['prompt']);

        DB::transaction(function () use ($aiEmployee, $data, $request) {
            AiEmployee::whereKey($aiEmployee->id)->lockForUpdate()->first();
            $draft = $aiEmployee->draftPrompt();
            $fields = ['prompt' => $data['prompt'], 'note' => $data['note'] ?? $draft?->note, 'author' => $request->user()->name];
            $draft ? $draft->update($fields) : $aiEmployee->prompts()->create($fields + [
                'version' => (int) $aiEmployee->prompts()->max('version') + 1, 'status' => 'draft']);
        });

        return redirect()->route('admin.ai-employees.edit', $aiEmployee)->with('success', 'Draft saved. Customers still get the published version until you publish.');
    }

    /** The draft becomes the version in use; the previous published one is archived. */
    public function publish(Request $request, AiEmployee $aiEmployee)
    {
        $draft = $aiEmployee->draftPrompt();
        if (! $draft) {
            return back()->withErrors(['employee' => 'There is no draft to publish.']);
        }
        DB::transaction(function () use ($aiEmployee, $draft) {
            AiEmployee::whereKey($aiEmployee->id)->lockForUpdate()->first();
            $aiEmployee->prompts()->where('status', 'published')->update(['status' => 'archived']);
            $draft->update(['status' => 'published', 'published_at' => now()]);
        });

        return back()->with('success', "Version {$draft->version} published.".($aiEmployee->isLive() ? ' Customers get it from the next message.' : ''));
    }

    /** Copy an older version into the draft (to roll back or start from it). */
    public function copyToDraft(Request $request, AiEmployee $aiEmployee, AiEmployeePrompt $prompt)
    {
        abort_unless($prompt->ai_employee_id === $aiEmployee->id, 404);
        DB::transaction(function () use ($aiEmployee, $prompt, $request) {
            AiEmployee::whereKey($aiEmployee->id)->lockForUpdate()->first();
            $fields = ['prompt' => $prompt->prompt, 'note' => "From v{$prompt->version}", 'author' => $request->user()->name];
            ($draft = $aiEmployee->draftPrompt()) ? $draft->update($fields) : $aiEmployee->prompts()->create($fields + [
                'version' => (int) $aiEmployee->prompts()->max('version') + 1, 'status' => 'draft']);
        });

        return redirect()->route('admin.ai-employees.edit', $aiEmployee)->with('success', "Version {$prompt->version} copied into the draft.");
    }

    /** The platform rules shared by every AI employee (empty = the default text). */
    public function updateRules(Request $request)
    {
        $rules = $request->validate(['platform_rules' => ['nullable', 'string', 'max:10000', 'not_regex:/\[TO FILL/i']])['platform_rules'] ?? null;
        $rules = str_replace(["\r\n", "\r"], "\n", (string) $rules); // browsers send CRLF
        $rules = trim($rules) !== '' && trim($rules) !== trim(AiEmployeeTemplates::PLATFORM_RULES) ? trim($rules) : null;
        SalesAssistantSetting::current()->update(['platform_rules' => $rules]);

        return back()->with('success', $rules ? 'Platform rules saved.' : 'Platform rules reset to the default.');
    }

    private function validated(Request $request, ?AiEmployee $employee): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', 'regex:/^[\pL][\pL \'’-]*$/u'],
            'role' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(array_keys(AiEmployee::KINDS))],
            'status' => ['required', Rule::in(array_keys(AiEmployee::STATUSES))],
            'audience' => ['nullable', 'string', 'max:200'],
            'channels' => ['nullable', 'array'],
            'channels.*' => [Rule::in(array_keys(AiEmployee::CHANNELS))],
            'scales' => ['required', 'array'],
            'scales.*' => ['integer', 'between:1,5'],
            'traits' => ['nullable', 'string', 'max:300'],
            'manner' => ['nullable', 'string', 'max:2000'],
            'say' => ['nullable', 'string', 'max:500'],
            'avoid' => ['nullable', 'string', 'max:500'],
            'handover' => ['nullable', 'string', 'max:1000'],
        ], ['name.regex' => 'Use letters only (the name customers see).']);

        $data['scales'] = collect(AiEmployee::SCALES)->mapWithKeys(fn ($s, $k) => [$k => max(1, min(5, (int) ($data['scales'][$k] ?? 3)))])->all();
        $data['traits'] = array_values(array_filter(array_map('trim', explode(',', (string) ($data['traits'] ?? ''))), fn ($t) => $t !== ''));
        $data['channels'] = array_values($data['channels'] ?? []);

        if (in_array($data['status'], AiEmployee::ACTIVE, true)) {
            $errors = [];
            if (! in_array($data['kind'], AiEmployee::RUNNABLE_KINDS, true)) {
                $errors[] = 'This role cannot talk to customers yet — it stays a draft.';
            } elseif (! $employee?->publishedPrompt()) {
                $errors[] = 'Publish a prompt version before switching this employee to Shadow or Live.';
            } elseif (AiEmployee::where('kind', $data['kind'])->whereIn('status', AiEmployee::ACTIVE)->when($employee, fn ($q) => $q->whereKeyNot($employee->id))->exists()) {
                $errors[] = 'Another '.mb_strtolower(AiEmployee::KINDS[$data['kind']]).' is in shadow or live already: switch it to Draft first.';
            }
            if ($errors) {
                throw ValidationException::withMessages(['status' => $errors]);
            }
        }

        return $data;
    }
}
