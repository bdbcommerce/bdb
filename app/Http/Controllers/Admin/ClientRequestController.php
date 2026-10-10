<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\KnowledgeEntry;
use App\Services\ClientRequestResolver;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Inbox of customer questions, grouped by the AI's topic. Admins answer a group with a
 * knowledge entry (the AI then matches other open questions to it) or dismiss it.
 */
class ClientRequestController extends Controller
{
    /** Requests scanned to build the grouped list. */
    private const SCAN_LIMIT = 2000;

    public function index(Request $request)
    {
        $status = is_string($s = $request->query('status')) && ($s === 'all' || array_key_exists($s, ClientRequest::STATUSES)) ? $s : 'open';
        $channel = is_string($c = $request->query('channel')) && array_key_exists($c, ChatConversation::CHANNELS) ? $c : null;

        $requests = ClientRequest::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->latest()
            ->limit(self::SCAN_LIMIT)
            ->get();

        // Most-asked first, then most recent.
        $groups = $requests
            ->groupBy(fn (ClientRequest $r) => $r->topic ?? 'request-'.$r->id)
            ->map(fn ($items) => (object) [
                'first' => $items->first(),
                'topic' => $items->first()->topic,
                'count' => $items->count(),
                'samples' => $items->pluck('question')->unique()->take(3)->values(),
                'channels' => $items->pluck('channel')->unique()->values(),
                'locales' => $items->pluck('locale')->unique()->values(),
                'latest' => $items->max('created_at'),
            ])
            ->sortBy([['count', 'desc'], ['latest', 'desc']])
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator($groups->forPage($page, 30)->values(), $groups->count(), 30, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('admin.client-requests.index', [
            'groups' => $paginator,
            'status' => $status,
            'statuses' => ClientRequest::STATUSES,
            'channel' => $channel,
            'channels' => ChatConversation::CHANNELS,
            'counts' => ClientRequest::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(ClientRequest $clientRequest, ClientRequestResolver $resolver)
    {
        $group = ClientRequest::sameGroupAs($clientRequest)->with('knowledgeEntry')->latest()->get();
        $entry = $clientRequest->knowledgeEntry ?? $group->pluck('knowledgeEntry')->filter()->first();

        return view('admin.client-requests.show', [
            'request' => $clientRequest,
            'group' => $group,
            'openCount' => $group->where('status', 'open')->count(),
            'entry' => $entry,
            'pendingCustomers' => $entry ? $resolver->pendingNotifications($entry)->unique('external_id')->count() : 0,
            'entries' => KnowledgeEntry::orderBy('type')->orderBy('sort_order')->get(),
            'statuses' => ClientRequest::STATUSES,
        ]);
    }

    /** Answer the group with an existing knowledge entry. */
    public function link(Request $request, ClientRequest $clientRequest, ClientRequestResolver $resolver)
    {
        $entry = KnowledgeEntry::findOrFail($request->validate(['knowledge_entry_id' => 'required|integer|exists:knowledge_entries,id'])['knowledge_entry_id']);

        return redirect()->route('admin.client-requests.show', $clientRequest)
            ->with('success', $resolver->answerWith($clientRequest, $entry));
    }

    public function dismiss(ClientRequest $clientRequest)
    {
        $count = ClientRequest::sameGroupAs($clientRequest)->open()->update(['status' => 'dismissed']);

        return redirect()->route('admin.client-requests.index')->with('success', "Dismissed {$count} ".str('request')->plural($count).'.');
    }

    /** Send the answer to Telegram customers who asked. */
    public function notify(Request $request, ClientRequest $clientRequest, ClientRequestResolver $resolver)
    {
        $entry = KnowledgeEntry::findOrFail($request->validate(['knowledge_entry_id' => 'required|integer|exists:knowledge_entries,id'])['knowledge_entry_id']);

        // Two quick submits (double click, two admins) must not message customers twice.
        $lock = Cache::lock("client-requests.notify.{$entry->id}", 120);
        if (! $lock->get()) {
            return redirect()->route('admin.client-requests.show', $clientRequest)
                ->with('success', 'This answer is already being sent. Refresh in a moment.');
        }

        try {
            ['sent' => $sent, 'failed' => $failed] = $resolver->notifyCustomers($entry, $request->user()->name);
        } finally {
            $lock->release();
        }

        $message = "Answer sent to {$sent} Telegram ".str('customer')->plural($sent).'.';
        if ($failed) {
            $message .= " {$failed} could not be reached (they may have blocked the bot).";
        }

        return redirect()->route('admin.client-requests.show', $clientRequest)->with('success', $message);
    }
}
