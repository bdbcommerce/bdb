<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin → Sales assistant → Settings and Prompt. */
class SalesAssistantController extends Controller
{
    public function edit()
    {
        return view('admin.sales-assistant.settings', [
            'settings' => SalesAssistantSetting::current(),
            'employees' => TelegramUser::where('is_employee', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $time = ['bail', 'required', 'string', 'date_format:H:i'];
        $data = $request->validate([
            'bot_mode' => ['required', Rule::in(array_keys(SalesAssistantSetting::MODES))],
            'business_mode' => ['required', Rule::in(array_keys(SalesAssistantSetting::MODES))],
            'wait_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', Rule::in(array_keys(SalesAssistantSetting::DAYS))],
            'work_start' => $time,
            'work_end' => [...$time, Rule::when(is_string($request->input('work_start')), 'after:work_start')],
            'off_hours_mode' => ['sometimes', 'required', Rule::in(array_keys(SalesAssistantSetting::OFF_HOURS_MODES))],
            'holidays' => ['sometimes', 'nullable', 'string', 'max:5000', function ($attribute, $value, $fail) {
                foreach (preg_split('/\R/u', (string) $value) as $i => $line) {
                    if (trim($line) !== '' && SalesAssistantSetting::parseHoliday($line) === null) {
                        $fail('Holidays, line '.($i + 1).' ("'.mb_strimwidth(trim($line), 0, 40, '…').'"): write a date like 2027-03-21 or 21.03.2027, or a range of up to 31 days, then the name.');

                        return;
                    }
                }
            }],
            'morning_summary' => ['sometimes', 'boolean'],
            'require_assignment' => ['sometimes', 'boolean'],
            'greeting_channels' => ['sometimes', 'array'],
            'greeting_channels.*' => [Rule::in(array_keys(SalesAssistantSetting::GREETING_CHANNELS))],
            'greeting_prompt' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'new_contact_mode' => ['sometimes', 'required', Rule::in(array_keys(SalesAssistantSetting::NEW_CONTACT_MODES))],
            'employee_roles' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'remind_after_hours' => ['required', 'integer', 'min:1', 'max:48'],
            'close_after_hours' => ['required', 'integer', 'min:1', 'max:168', 'gt:remind_after_hours'],
            'follow_up_days' => ['required', 'integer', 'min:1', 'max:90'],
            'large_order_sum' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000000'],
            'lead_remind_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:480'],
            'draft_nudge_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:60'],
            'reorder_hints' => ['sometimes', 'boolean'],
            'expert_remind_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:480'],
            'expert_escalate_minutes' => ['sometimes', 'required', 'integer', 'min:10', 'max:960', 'gt:expert_remind_minutes'],
            'model' => ['required', Rule::in(array_keys(SalesAssistantSetting::MODELS))],
            'employee_ids' => ['nullable', 'string', 'max:3000'],
            'claude_data' => ['sometimes', 'array'],
            'claude_data.*' => ['boolean'],
        ], [
            'work_days.required' => 'Pick at least one working day.',
            'work_end.after' => 'The end of the working day must be after its start.',
            'close_after_hours.gt' => 'Close incomplete requests later than the reminder.',
            'expert_escalate_minutes.gt' => 'Pass to the main admin later than the reminder.',
        ]);
        if ($request->has('claude_data_section')) { // unticked boxes send nothing: every key is stored, off = false
            $data['claude_data'] = collect(SalesAssistantSetting::CLAUDE_DATA)->keys()
                ->mapWithKeys(fn (string $key) => [$key => (bool) (((array) ($data['claude_data'] ?? []))[$key] ?? false)])->all();
        }
        if ($request->has('greeting_section')) { // unticking every chat sends no array at all
            $data['greeting_channels'] = array_values(array_unique((array) ($data['greeting_channels'] ?? [])));
        }
        if (array_key_exists('greeting_prompt', $data)) {
            $prompt = trim(str_replace("\r\n", "\n", (string) $data['greeting_prompt']));
            $data['greeting_prompt'] = $prompt === '' || $prompt === trim(SalesAssistantSetting::defaultGreetingPrompt()) ? null : $prompt;
        }
        if (array_key_exists('employee_roles', $data)) {
            $roles = collect(preg_split('/\R/u', (string) $data['employee_roles']))->map(fn ($r) => trim(mb_substr($r, 0, 60)))->filter()->unique()->values();
            $data['employee_roles'] = $roles->isEmpty() || $roles->all() === SalesAssistantSetting::DEFAULT_EMPLOYEE_ROLES ? null : $roles->implode("\n");
        }
        if (array_key_exists('holidays', $data)) {
            $lines = collect(preg_split('/\R/u', (string) $data['holidays']))->map(fn ($l) => trim($l))->filter()->values();
            $data['holidays'] = $lines->isEmpty() ? null : $lines->implode("\n");
        }
        $data['work_days'] = array_values(array_unique(array_map('intval', $data['work_days'])));
        sort($data['work_days']);

        SalesAssistantSetting::current()->update($data);

        return back()->with('success', 'Sales assistant settings saved.');
    }

    public function prompt()
    {
        $settings = SalesAssistantSetting::current();

        return view('admin.sales-assistant.prompt', [
            'settings' => $settings,
            'defaultPrompt' => SalesAssistantSetting::defaultPrompt(),
            'preview' => $settings->systemPrompt(),
        ]);
    }

    public function updatePrompt(Request $request)
    {
        $data = $request->validate(['prompt' => ['required', 'string', 'min:200', 'max:30000']]);
        $text = str_replace("\r\n", "\n", $data['prompt']);
        // Same as the built-in text → store nothing, so later improvements of the file apply.
        SalesAssistantSetting::current()->update(['prompt' => trim($text) === trim(SalesAssistantSetting::defaultPrompt()) ? null : $text]);

        return back()->with('success', 'Prompt saved.');
    }
}
