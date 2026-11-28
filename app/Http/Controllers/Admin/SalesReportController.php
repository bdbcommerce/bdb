<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvoiceParserSetting;
use App\Models\SalesReport;
use App\Models\SalesReportSetting;
use App\Services\Scheduling\ScheduledTasks;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin → Telegram → Sales report: settings, "Send now" and the reports sent so far. */
class SalesReportController extends Controller
{
    public function index()
    {
        return view('admin.sales-report.index', [
            'settings' => SalesReportSetting::current(),
            'reports' => SalesReport::latest('id')->paginate(20),
            'chatId' => SalesReportSetting::chatId(),
            'dailyChatId' => SalesReportSetting::dailyChatId(),
            'nextDaily' => ScheduledTasks::nextRun('sales-report-daily'),
            'sourceChatId' => InvoiceParserSetting::current()->source_chat_id,
            'hasKey' => filled(InvoiceParserSetting::current()->apiKey()),
            'nextRun' => ScheduledTasks::nextRun('sales-report'),
            'lastRun' => ScheduledTasks::latest('sales-report'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'daily_enabled' => ['boolean'],
            'language' => ['required', Rule::in(array_keys(SalesReportSetting::LANGUAGES))],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(array_keys(SalesReportSetting::CHANNELS))],
            'instructions' => ['nullable', 'string', 'max:3000'],
        ], ['channels.required' => 'Pick at least one kind of chat.']);

        SalesReportSetting::current()->update([
            'enabled' => $request->boolean('enabled'),
            'daily_enabled' => $request->boolean('daily_enabled'),
            'language' => $data['language'],
            'channels' => array_values(array_unique($data['channels'])),
            'instructions' => $data['instructions'] ?? null,
        ]);

        return back()->with('success', 'Sales report settings saved.');
    }

    public function run(Request $request)
    {
        $daily = $request->input('period') === 'day';
        if ($daily && ! SalesReportSetting::dailyChatId()) {
            return back()->withErrors(['run' => 'Set the daily report recipient first: the JSON recipient (your Telegram user ID) in Invoice parser → Settings.']);
        }
        if (! $daily && ! SalesReportSetting::chatId()) {
            return back()->withErrors(['run' => 'Set the Telegram group first: the source chat ID in Invoice parser → Settings (a group ID, starts with -100).']);
        }
        $error = ScheduledTasks::runNow($daily ? 'sales-report-daily' : 'sales-report', $request->user()?->id);

        return $error ? back()->withErrors(['run' => $error])
            : back()->with('success', 'The '.($daily ? 'daily' : 'weekly').' report is being prepared: Claude needs a few minutes, then the messages appear in '.($daily ? 'the JSON recipient\'s chat' : 'the group').'. Refresh this page to see it.');
    }

    public function show(SalesReport $salesReport)
    {
        return view('admin.sales-report.show', ['report' => $salesReport, 'previous' => $salesReport->metrics ? $salesReport->previous() : null]);
    }
}
