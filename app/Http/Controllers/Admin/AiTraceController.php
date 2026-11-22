<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiEmployee;
use App\Models\AiSandboxChat;
use App\Models\AiTrace;
use App\Models\SalesAssistantSetting;
use App\Services\SalesAssistant\SalesAssistant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The AI training sandbox (an admin plays the customer, the AI employee answers with a real Claude call, nothing
 * reaches Telegram) and the traces: every step of a turn — customer → Laravel → Claude API → Laravel → Telegram —
 * with what was sent there. Live customer turns are traced only while dev mode is on.
 */
class AiTraceController extends Controller
{
    public function sandbox(Request $request)
    {
        $chat = $request->integer('chat')
            ? AiSandboxChat::where('user_id', $request->user()->id)->findOrFail($request->integer('chat'))
            : AiSandboxChat::where('user_id', $request->user()->id)->latest('id')->first();
        $traces = $chat?->traces()->get() ?? collect();

        return view('admin.ai-team.sandbox', [
            'chat' => $chat,
            'employees' => AiEmployee::with('prompts')->orderBy('sort')->orderBy('id')->get(),
            'traces' => $traces,
            'trace' => $request->integer('trace') ? $traces->firstWhere('id', $request->integer('trace')) : $traces->last(),
            'chats' => AiSandboxChat::where('user_id', $request->user()->id)->with('employee')->latest('id')->limit(10)->get(),
        ]);
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'ai_employee_id' => ['required', Rule::exists('ai_employees', 'id')],
            'prompt' => ['required', Rule::in(['published', 'draft'])],
            'via' => ['required', Rule::in(['bot', 'assistant'])],
        ]);
        $chat = AiSandboxChat::create($data + ['user_id' => $request->user()->id, 'messages' => [], 'state' => []]);

        return redirect()->route('admin.ai-team.sandbox', ['chat' => $chat->id]);
    }

    public function message(Request $request, AiSandboxChat $chat, SalesAssistant $assistant)
    {
        abort_unless($chat->user_id === $request->user()->id, 404);
        $text = trim($request->validate(['message' => ['required', 'string', 'max:2000']])['message']);
        @set_time_limit(150); // one real Claude call

        $trace = $assistant->sandbox($chat, $text);

        return redirect()->route('admin.ai-team.sandbox', ['chat' => $chat->id, 'trace' => $trace?->id]);
    }

    public function index(Request $request)
    {
        $source = in_array($request->query('source'), ['live', 'sandbox'], true) ? $request->query('source') : 'live';

        return view('admin.ai-team.traces', [
            'source' => $source,
            'devMode' => (bool) SalesAssistantSetting::current()->dev_mode,
            'traces' => AiTrace::where('source', $source)->with('employee', 'conversation.telegramUser')->latest('id')->paginate(30)->withQueryString(),
        ]);
    }

    public function show(AiTrace $trace)
    {
        return view('admin.ai-team.trace', ['trace' => $trace->load('employee', 'conversation.telegramUser')]);
    }

    public function devMode(Request $request)
    {
        $on = $request->boolean('dev_mode');
        SalesAssistantSetting::current()->update(['dev_mode' => $on]);

        return back()->with('success', $on
            ? 'Dev mode is on: every real customer turn is traced (kept '.AiTrace::KEEP_DAYS.' days).'
            : 'Dev mode is off: real customer turns are no longer traced.');
    }
}
