<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\KnowledgeEntry;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Collection;

/**
 * Admin answers to customer questions: a knowledge entry resolves a group of similar
 * requests, the AI finds other open requests it also answers, and Telegram customers
 * who asked can be sent the answer in their language.
 */
class ClientRequestResolver
{
    /** How many open requests the AI checks against a new answer in one call. */
    private const MATCH_LIMIT = 100;

    public function __construct(private ChatAssistant $assistant, private TelegramApi $telegram) {}

    /**
     * An admin answered this request's group with the entry: resolve the group, then let the AI
     * match other open requests to it. Returns a summary for the admin.
     */
    public function answerWith(ClientRequest $request, KnowledgeEntry $entry): string
    {
        $inGroup = $this->resolveGroup($request, $entry);
        $matched = $this->matchOthers($entry);

        $message = "Answered {$inGroup} ".str('request')->plural($inGroup).' in this group';

        return $message.($matched ? ", and the AI matched {$matched} more open ".str('request')->plural($matched).' to this answer.' : '.');
    }

    /** Resolve the open requests in this request's group with the entry. Returns how many. */
    public function resolveGroup(ClientRequest $request, KnowledgeEntry $entry): int
    {
        $ids = ClientRequest::sameGroupAs($request)->open()->pluck('id');

        return $this->resolve($ids->all(), $entry);
    }

    /** Let the AI find other open requests the entry answers, and resolve them. Returns how many. */
    public function matchOthers(KnowledgeEntry $entry): int
    {
        if (! config('services.gemini.key')) {
            return 0;
        }

        $questions = ClientRequest::open()->latest()->limit(self::MATCH_LIMIT)->pluck('question', 'id')->all();

        return $this->resolve($this->assistant->matchQuestions($entry, $questions), $entry);
    }

    /**
     * Telegram customers whose resolved requests have this entry as the answer and who were not sent it yet.
     *
     * @return Collection<int, ClientRequest>
     */
    public function pendingNotifications(KnowledgeEntry $entry)
    {
        return ClientRequest::where('knowledge_entry_id', $entry->id)
            ->where('status', 'resolved')
            ->where('channel', 'telegram')
            ->whereNotNull('external_id')
            ->whereNull('notified_at')
            ->get();
    }

    /**
     * Send the entry's answer to each Telegram customer who asked (once per customer, in their language).
     *
     * @return array{sent: int, failed: int}
     */
    public function notifyCustomers(KnowledgeEntry $entry, string $author): array
    {
        $sent = $failed = 0;

        foreach ($this->pendingNotifications($entry)->groupBy('external_id') as $chatId => $requests) {
            $request = $requests->sortByDesc('id')->first();
            $locale = TelegramUser::where('telegram_id', $chatId)->value('locale') ?? $request->locale;

            $text = __('chat.tg_followup', [
                'question' => mb_strimwidth($request->question, 0, 120, '…'),
                'answer' => $entry->getTranslation('answer', $locale, true),
            ], $locale);

            if (! $this->telegram->sendMessage($chatId, $text)) {
                $failed++;

                continue;
            }

            $sent++;
            ClientRequest::whereKey($requests->pluck('id'))->update(['notified_at' => now()]);

            // Show it in the customer's latest conversation, like any staff message.
            ChatConversation::where('channel', 'telegram')->where('external_id', $chatId)
                ->latest('updated_at')->first()
                ?->appendAdminMessage($text, $author);
        }

        return compact('sent', 'failed');
    }

    private function resolve(array $ids, KnowledgeEntry $entry): int
    {
        if ($ids === []) {
            return 0;
        }

        return ClientRequest::whereKey($ids)->update([
            'status' => 'resolved',
            'knowledge_entry_id' => $entry->id,
            'resolved_at' => now(),
        ]);
    }
}
