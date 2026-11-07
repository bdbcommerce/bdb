<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use Illuminate\Http\Request;

/**
 * Telegram chats where the customer wrote last and nobody has answered yet: chats with the
 * Premium account (the owner did not reply in Telegram) and bot chats (no staff reply).
 */
class AwaitingReplyController extends Controller
{
    public const SORTS = ['oldest' => 'Waiting longest', 'newest' => 'Newest first'];

    public function index(Request $request)
    {
        $sort = is_string($s = $request->query('sort')) && array_key_exists($s, self::SORTS) ? $s : 'oldest';
        $channel = is_string($c = $request->query('channel')) && in_array($c, ChatConversation::REPLY_CHANNELS, true) ? $c : null;

        $conversations = ChatConversation::awaitingReply()
            ->with('telegramUser')
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->orderBy('last_message_at', $sort === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.awaiting-replies.index', [
            'conversations' => $conversations,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'channel' => $channel,
            'channels' => array_intersect_key(ChatConversation::CHANNELS, array_flip(ChatConversation::REPLY_CHANNELS)),
            'counts' => ChatConversation::awaitingReply()->toBase()->selectRaw('channel, count(*) as n')->groupBy('channel')->pluck('n', 'channel'),
        ]);
    }

    public function handled(Request $request, ChatConversation $chatConversation)
    {
        $chatConversation->markHandled();

        return back()->with('success', 'Marked as answered. It comes back to the list when the customer writes again.');
    }
}
