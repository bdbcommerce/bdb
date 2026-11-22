<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ChatConversation;
use App\Services\ChatAssistant;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class ChatConversationController extends Controller
{
    public const FILTERS = [
        'all' => 'All',
        'unanswered' => 'Unanswered questions',
        'leads' => 'With callback request',
    ];

    public const PER_PAGE = 25;

    public const HISTORY_DAYS = 7;

    public const MAX_DAYS = 364;

    private const MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'oga' => 'audio/ogg', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'pdf' => 'application/pdf',
    ];

    public function index(Request $request)
    {
        // Conversations: every chat, any source. Telegram → Chats: only the bot's chats (customer + assistant).
        $telegramOnly = $request->routeIs('admin.telegram-chats.index');
        $channels = ChatConversation::CHANNELS;

        $filter = is_string($f = $request->query('filter')) && array_key_exists($f, self::FILTERS) ? $f : 'all';
        $channel = $telegramOnly ? 'telegram'
            : (is_string($ch = $request->query('channel')) && array_key_exists($ch, $channels) ? $ch : null);

        $matches = fn ($q, string $table) => $q
            ->when($filter === 'unanswered', fn ($q) => $q->where($table.'.unanswered_count', '>', 0))
            ->when($filter === 'leads', fn ($q) => $q->whereNotNull($table.'.chat_lead_id'));

        // One row per chat, like a messenger: a Telegram chat's newest (matching) session; website chats one by one.
        $conversations = ChatConversation::query()
            ->with('telegramUser')
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->tap(fn ($q) => $matches($q, 'chat_conversations'))
            ->where(fn ($q) => $q->where('channel', 'web')->orWhereNull('external_id')
                ->orWhereNotExists(fn ($q) => $matches($q->selectRaw('1')->from('chat_conversations as newer')
                    ->whereColumn('newer.channel', 'chat_conversations.channel')
                    ->whereColumn('newer.external_id', 'chat_conversations.external_id')
                    ->whereRaw("coalesce(newer.business_connection_id, '') = coalesce(chat_conversations.business_connection_id, '')")
                    ->whereColumn('newer.id', '>', 'chat_conversations.id'), 'newer')))
            ->latest('updated_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.chat-conversations.index', [
            'conversations' => $conversations,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'channel' => $channel,
            'channels' => $channels,
            'keepDays' => ChatConversation::KEEP_DAYS,
            'indexRoute' => $telegramOnly ? 'admin.telegram-chats.index' : 'admin.chat-conversations.index',
            'telegramOnly' => $telegramOnly,
        ]);
    }

    public function show(Request $request, ChatConversation $chatConversation)
    {
        // The chat's last 7 days across its sessions; "Show earlier" adds 7 days at a time (up to a year).
        $days = (int) $request->query('days', self::HISTORY_DAYS);
        $days = max(self::HISTORY_DAYS, min(self::MAX_DAYS, $days - $days % self::HISTORY_DAYS));

        return view('admin.chat-conversations.show', [
            'conversation' => $chatConversation->load('lead', 'telegramUser'),
            'history' => $chatConversation->history($days),
            'days' => $days,
            'latest' => $chatConversation->latestSession(),
            'maxDays' => self::MAX_DAYS,
        ]);
    }

    /** Send a message to the Telegram customer as the bot, and log it in the conversation. */
    public function reply(Request $request, ChatConversation $chatConversation, TelegramApi $api)
    {
        abort_unless($chatConversation->channel === 'telegram' && $chatConversation->external_id, 404);

        $text = trim($request->validate(
            ['message' => ['required', 'string', 'max:4000', 'not_regex:/\[TO FILL/i']],
            ['message.not_regex' => 'Replace the [TO FILL: …] placeholders with real information before sending.'],
        )['message']);

        if (! $api->sendMessage($chatConversation->external_id, $text)) {
            return back()->withInput()->with('telegram_error',
                'Telegram did not deliver the message. The customer may have blocked the bot — see storage/logs/laravel.log.');
        }

        // Opened from an older session: the reply belongs at the end of the chat, in its newest session.
        $target = $chatConversation->latestSession();
        $target->appendAdminMessage($text, $request->user()->name);

        return redirect()->route('admin.chat-conversations.show', $target)->with('success', 'Message sent to the customer.');
    }

    /** An AI-drafted reply for the admin to edit before sending (JSON). */
    public function suggest(ChatConversation $chatConversation, ChatAssistant $assistant)
    {
        abort_unless($chatConversation->channel === 'telegram' && $chatConversation->external_id, 404);

        $history = collect($chatConversation->latestSession()->textMessages())
            ->map(fn (array $m) => ['role' => $m['role'] === 'user' ? 'user' : 'assistant', 'content' => $m['content']])
            ->slice(-12)
            ->values()
            ->all();
        while ($history && $history[0]['role'] !== 'user') {
            array_shift($history);
        }

        $reply = $history && AiModel::available()
            ? $assistant->suggestStaffReply($history, $chatConversation->locale)
            : null;

        return $reply
            ? response()->json(['reply' => $reply])
            : response()->json(['message' => 'The AI could not suggest a reply right now.'], 422);
    }

    /** Shows a file a Telegram customer sent, fetched from Telegram on demand. */
    public function media(string $fileId, TelegramApi $api)
    {
        // Photos, voice, audio, video and stickers only: documents (apps such as .apk, archives, PDFs…) are never downloaded.
        $file = $api->downloadFile($fileId);
        abort_if($file === null, 404, 'Not available: files, apps (.apk), archives and documents are never downloaded from Telegram — open them in the Telegram app. Photos, voice and video may also be over 20 MB or expired.');

        // Telegram often answers with application/octet-stream; guess from the extension for inline display.
        $type = $file['type'] && $file['type'] !== 'application/octet-stream'
            ? $file['type']
            : (self::MIME[strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))] ?? 'application/octet-stream');

        return response($file['body'], 200, [
            'Content-Type' => $type,
            // Pictures, video and audio open in the browser; anything else is downloaded.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                preg_match('#^(image|video|audio)/#', $type) ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name = str_replace(['/', '\\', '%'], '_', preg_replace('/[\x00-\x1F\x7F]/', '', $file['name'])) ?: 'file',
                Str::ascii($name) !== '' ? preg_replace('/[^\x20-\x7E]/', '_', Str::ascii($name)) : 'file',
            ),
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // Never run anything a customer uploaded (e.g. an HTML or SVG document).
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function destroy(ChatConversation $chatConversation)
    {
        $chatConversation->delete();

        return redirect()->route('admin.chat-conversations.index')->with('success', 'Conversation deleted.');
    }
}
