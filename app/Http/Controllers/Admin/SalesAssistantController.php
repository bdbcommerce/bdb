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
            'testers' => TelegramUser::where('is_tester', true)->orderBy('name')->get(),
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
            'remind_after_hours' => ['required', 'integer', 'min:1', 'max:48'],
            'close_after_hours' => ['required', 'integer', 'min:1', 'max:168', 'gt:remind_after_hours'],
            'follow_up_days' => ['required', 'integer', 'min:1', 'max:90'],
            'model' => ['required', Rule::in(array_keys(SalesAssistantSetting::MODELS))],
            'employee_ids' => ['nullable', 'string', 'max:3000'],
        ], [
            'work_days.required' => 'Pick at least one working day.',
            'work_end.after' => 'The end of the working day must be after its start.',
            'close_after_hours.gt' => 'Close incomplete requests later than the reminder.',
        ]);
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
