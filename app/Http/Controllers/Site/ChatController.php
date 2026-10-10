<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ClientRequest;
use App\Services\ChatAssistant;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

use function Illuminate\Support\defer;

class ChatController extends Controller
{
    public function message(Request $request, ChatAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'locale' => 'required|in:uz,ru,en',
            'conversation' => 'nullable|uuid',
            'page' => 'nullable|string|max:200',
            'messages' => 'required|array|min:1|max:20',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:1000',
        ]);

        // Keep the request small: last 12 turns, starting with a user turn.
        $history = array_slice($data['messages'], -12);
        while ($history && $history[0]['role'] !== 'user') {
            array_shift($history);
        }

        $result = config('services.gemini.key') && $history
            ? $assistant->reply($history, $data['locale'], $data['page'] ?? null)
            : $assistant->fallbackReply($data['locale']);

        $question = end($data['messages']);
        if (! empty($data['conversation']) && $question['role'] === 'user') {
            $conversation = ChatConversation::firstOrNew(['uuid' => $data['conversation']], [
                'locale' => $data['locale'],
                'page' => $data['page'] ?? null,
            ]);
            $conversation->appendExchange($question['content'], $result['reply'], $result['answered']);
            ClientRequest::record($conversation, $question['content'], $result);
        }

        // Internal fields (inbox tagging) are not sent to the browser.
        return response()->json(Arr::only($result, ['reply', 'suggestions', 'offer_callback']));
    }

    public function lead(Request $request, TelegramNotifier $telegram): JsonResponse
    {
        $data = $request->validate([
            'locale' => 'required|in:uz,ru,en',
            'conversation' => 'nullable|uuid',
            'name' => 'required|string|max:100',
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'topic' => 'nullable|string|max:500',
            'page_url' => 'nullable|string|max:255',
            'messages' => 'nullable|array|max:20',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:1000',
        ], [
            'phone.regex' => __('chat.phone_invalid', [], $request->input('locale', 'uz')),
        ]);

        $lead = ChatLead::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'topic' => $data['topic'] ?? null,
            'locale' => $data['locale'],
            'page_url' => $data['page_url'] ?? null,
            'transcript' => $data['messages'] ?? [],
        ]);

        if (! empty($data['conversation'])) {
            ChatConversation::where('uuid', $data['conversation'])->update(['chat_lead_id' => $lead->id]);
        }

        // Sent after the response, so a slow Telegram API never delays the visitor.
        defer(fn () => $telegram->newLead($lead));

        return response()->json(['ok' => true]);
    }
}
