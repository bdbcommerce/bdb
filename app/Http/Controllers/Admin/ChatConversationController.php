<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

    private const MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'oga' => 'audio/ogg', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'pdf' => 'application/pdf',
    ];

    public function index(Request $request)
    {
        $filter = is_string($f = $request->query('filter')) && array_key_exists($f, self::FILTERS) ? $f : 'all';
        $channel = is_string($ch = $request->query('channel')) && array_key_exists($ch, ChatConversation::CHANNELS) ? $ch : null;

        $conversations = ChatConversation::query()
            ->with('telegramUser')
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->when($filter === 'unanswered', fn ($q) => $q->where('unanswered_count', '>', 0))
            ->when($filter === 'leads', fn ($q) => $q->whereNotNull('chat_lead_id'))
            ->latest('updated_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.chat-conversations.index', [
            'conversations' => $conversations,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'channel' => $channel,
            'channels' => ChatConversation::CHANNELS,
            'keepDays' => ChatConversation::KEEP_DAYS,
        ]);
    }

    public function show(ChatConversation $chatConversation)
    {
        return view('admin.chat-conversations.show', [
            'conversation' => $chatConversation->load('lead', 'telegramUser'),
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

        $chatConversation->appendAdminMessage($text, $request->user()->name);

        return redirect()->route('admin.chat-conversations.show', $chatConversation)->with('success', 'Message sent to the customer.');
    }

    /** An AI-drafted reply for the admin to edit before sending (JSON). */
    public function suggest(ChatConversation $chatConversation, ChatAssistant $assistant)
    {
        abort_unless($chatConversation->channel === 'telegram' && $chatConversation->external_id, 404);

        $history = collect($chatConversation->textMessages())
            ->map(fn (array $m) => ['role' => $m['role'] === 'user' ? 'user' : 'assistant', 'content' => $m['content']])
            ->slice(-12)
            ->values()
            ->all();
        while ($history && $history[0]['role'] !== 'user') {
            array_shift($history);
        }

        $reply = $history && config('services.gemini.key')
            ? $assistant->suggestStaffReply($history, $chatConversation->locale)
            : null;

        return $reply
            ? response()->json(['reply' => $reply])
            : response()->json(['message' => 'The AI could not suggest a reply right now.'], 422);
    }

    /** Shows a file a Telegram customer sent, fetched from Telegram on demand. */
    public function media(string $fileId, TelegramApi $api)
    {
        $file = $api->downloadFile($fileId);
        abort_if($file === null, 404, 'Telegram could not provide this file (it may be larger than 20 MB or expired).');

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
