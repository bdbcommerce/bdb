<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiEmployee;
use App\Models\AiShadowDraft;
use App\Services\SalesAssistant\SalesAssistant;
use Illuminate\Http\Request;

/** Shadow mode: replies AI employees drafted, waiting for a manager to send (as is or edited) or discard. */
class AiShadowDraftController extends Controller
{
    public function index()
    {
        return view('admin.ai-team.shadow', [
            'pending' => AiShadowDraft::where('status', 'pending')->with('employee', 'conversation.telegramUser')->latest('id')->get(),
            'decided' => AiShadowDraft::whereIn('status', ['sent', 'edited', 'discarded'])->with('employee', 'conversation.telegramUser')->latest('decided_at')->limit(30)->get(),
            'employees' => AiEmployee::whereIn('kind', AiEmployee::RUNNABLE_KINDS)->orderBy('sort')->get()
                ->map(fn (AiEmployee $e) => ['employee' => $e, 'stats' => AiShadowDraft::stats($e->id)]),
        ]);
    }

    public function send(Request $request, AiShadowDraft $draft, SalesAssistant $assistant)
    {
        if ($draft->status !== 'pending') {
            return back()->withErrors(['draft' => 'This reply was already '.mb_strtolower(AiShadowDraft::STATUSES[$draft->status] ?? $draft->status).'.']);
        }
        $text = str_replace(["\r\n", "\r"], "\n", trim($request->validate([
            'text' => ['required', 'string', 'max:3500', 'not_regex:/\[TO FILL/i'],
        ], ['text.not_regex' => 'Replace the [TO FILL: …] placeholders first.'])['text']));

        if (! $assistant->sendDraft($draft, $text, $request->user()->name)) {
            return back()->withInput()->withErrors(['draft' => 'Telegram did not deliver the message (the customer may have blocked the bot, or the Premium account may not allow replies).']);
        }

        return back()->with('success', 'Sent to the customer.');
    }

    public function discard(Request $request, AiShadowDraft $draft)
    {
        if ($draft->status === 'pending') {
            $draft->update(['status' => 'discarded', 'decided_by' => mb_substr($request->user()->name, 0, 100), 'decided_at' => now()]);
        }

        return back()->with('success', 'Discarded. Answer the customer yourself.');
    }
}
