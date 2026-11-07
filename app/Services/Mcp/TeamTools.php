<?php

namespace App\Services\Mcp;

use App\Models\AiInstruction;
use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\KnowledgeEntry;
use App\Models\McpSetting;
use App\Services\ClientRequestResolver;
use Illuminate\Support\Str;

/**
 * Team tools of the MCP server — only with an access token. They let Claude read the customer
 * conversations (website, Telegram bot, Premium account, groups) and teach the chat assistant:
 * knowledge entries and AI rules/info. New items are drafts (inactive) unless the admin lets
 * Claude publish directly. Phone numbers are never returned.
 */
class TeamTools
{
    /** Tool => [title, description, write?]. */
    public const TOOLS = [
        'list_conversations' => ['Conversations', 'Recent chats (website, Telegram bot, Premium account, groups) with a preview; filter by channel, waiting for reply, unanswered by the AI', false],
        'get_conversation' => ['Read a conversation', 'All messages of one conversation (customer, assistant, staff, account owner)', false],
        'list_open_questions' => ['Open customer questions', 'Questions the AI could not answer, grouped by topic, with how often they were asked', false],
        'list_knowledge' => ['Knowledge base', 'Existing knowledge entries (question/answer in uz, ru, en), searchable', false],
        'save_knowledge_entry' => ['Save a knowledge entry', 'Create or update a question/answer in all three languages that the chat assistant uses', true],
        'resolve_open_questions' => ['Resolve open questions', 'Mark a group of open questions as answered by an active knowledge entry', true],
        'get_ai_rules' => ['AI rules & info', 'The documents that steer the chat assistant (rules and facts)', false],
        'add_ai_note' => ['Add AI rule or info', 'Add a rules or information document for the chat assistant', true],
    ];

    public function __construct(private McpSetting $settings) {}

    public function schemas(): array
    {
        $tri = fn (int $max) => ['type' => 'object', 'required' => ['uz', 'ru', 'en'], 'properties' => [
            'uz' => ['type' => 'string', 'maxLength' => $max], 'ru' => ['type' => 'string', 'maxLength' => $max], 'en' => ['type' => 'string', 'maxLength' => $max],
        ]];

        return [
            'list_conversations' => ['type' => 'object', 'properties' => [
                'channel' => ['type' => 'string', 'enum' => array_keys(ChatConversation::CHANNELS)],
                'filter' => ['type' => 'string', 'enum' => ['all', 'waiting_for_reply', 'unanswered_by_ai'], 'default' => 'all'],
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 7],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            ]],
            'get_conversation' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]]],
            'list_open_questions' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30]]],
            'list_knowledge' => ['type' => 'object', 'properties' => [
                'type' => ['type' => 'string', 'enum' => array_keys(KnowledgeEntry::TYPES)],
                'query' => ['type' => 'string', 'maxLength' => 200],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            ]],
            'save_knowledge_entry' => ['type' => 'object', 'required' => ['type', 'question', 'answer'], 'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Give to update an existing entry; omit to create'],
                'type' => ['type' => 'string', 'enum' => array_keys(KnowledgeEntry::TYPES)],
                'question' => $tri(300),
                'answer' => $tri(3000),
                'keywords' => ['type' => 'string', 'maxLength' => 255],
                'link' => ['type' => 'string', 'description' => 'Optional page on the site', 'maxLength' => 255],
            ]],
            'resolve_open_questions' => ['type' => 'object', 'required' => ['request_id', 'knowledge_id'], 'properties' => [
                'request_id' => ['type' => 'integer', 'description' => 'Any request of the group, from list_open_questions'],
                'knowledge_id' => ['type' => 'integer', 'description' => 'An active knowledge entry that answers it'],
            ]],
            'get_ai_rules' => ['type' => 'object', 'properties' => []],
            'add_ai_note' => ['type' => 'object', 'required' => ['kind', 'title', 'content'], 'properties' => [
                'kind' => ['type' => 'string', 'enum' => array_keys(AiInstruction::KINDS)],
                'channel' => ['type' => 'string', 'enum' => array_keys(AiInstruction::CHANNELS), 'default' => 'all'],
                'title' => ['type' => 'string', 'maxLength' => 120],
                'content' => ['type' => 'string', 'description' => 'Markdown', 'maxLength' => AiInstruction::MAX_LENGTH],
            ]],
        ];
    }

    public function call(string $tool, array $args): array
    {
        return match ($tool) {
            'list_conversations' => $this->listConversations($args),
            'get_conversation' => $this->getConversation($args),
            'list_open_questions' => $this->openQuestions($args),
            'list_knowledge' => $this->listKnowledge($args),
            'save_knowledge_entry' => $this->saveKnowledge($args),
            'resolve_open_questions' => $this->resolve($args),
            'get_ai_rules' => $this->aiRules(),
            'add_ai_note' => $this->addNote($args),
        };
    }

    private function listConversations(array $args): array
    {
        $channel = in_array($args['channel'] ?? null, array_keys(ChatConversation::CHANNELS), true) ? $args['channel'] : null;
        $filter = $args['filter'] ?? 'all';
        $days = $this->int($args, 'days', 7, 1, 90);

        $rows = ChatConversation::query()->with('telegramUser')
            ->where('updated_at', '>=', now()->subDays($days))
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->when($filter === 'waiting_for_reply', fn ($q) => $q->awaitingReply())
            ->when($filter === 'unanswered_by_ai', fn ($q) => $q->where('unanswered_count', '>', 0))
            ->latest('updated_at')->limit($this->int($args, 'limit', 20, 1, 50))->get();

        return ['conversations' => $rows->map(fn (ChatConversation $c) => array_filter([
            'id' => $c->id,
            'channel' => $c->channel,
            'customer' => $this->customer($c),
            'locale' => $c->locale,
            'messages' => $c->message_count,
            'unanswered_by_ai' => $c->unanswered_count ?: null,
            'waiting_for_reply' => $c->awaiting_reply ?: null,
            'last_activity' => $c->updated_at?->toIso8601String(),
            'preview' => self::maskPhones(Str::limit((string) (collect($c->messages)->where('role', 'user')->whereNotNull('content')->last()['content'] ?? ''), 160)),
        ], fn ($v) => $v !== null && $v !== ''))->values()->all()];
    }

    private function getConversation(array $args): array
    {
        $conversation = ChatConversation::with('telegramUser')->find($this->int($args, 'id', 0, 0, PHP_INT_MAX));
        if (! $conversation) {
            throw new ToolError('No conversation with this id.');
        }

        $messages = collect($conversation->messages ?? [])->take(-200)->map(fn (array $m) => array_filter([
            'role' => $m['role'] ?? null,
            'sender' => $m['sender'] ?? ($m['author'] ?? null),
            'text' => isset($m['content']) ? self::maskPhones(Str::limit((string) $m['content'], 2000)) : null,
            'attachment' => isset($m['content']) ? null : ($m['type'] ?? 'other').(isset($m['caption']) ? ': '.self::maskPhones(Str::limit((string) $m['caption'], 500)) : ''),
            'at' => $m['at'] ?? null,
            'ai_had_no_answer' => ($m['role'] ?? null) === 'assistant' && ($m['answered'] ?? true) === false ? true : null,
            'bot_step' => ! empty($m['aside']) ? true : null,
            'edited' => ! empty($m['edited']) ? true : null,
            'deleted' => ! empty($m['deleted']) ? true : null,
        ], fn ($v) => $v !== null && $v !== ''))->values()->all();

        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'customer' => $this->customer($conversation),
            'locale' => $conversation->locale,
            'started' => $conversation->created_at?->toIso8601String(),
            'roles' => 'user = customer, assistant = AI, admin = BDB staff (admin panel), owner = Premium account owner, member = group member, bot = registration question',
            'messages' => $messages,
        ];
    }

    private function openQuestions(array $args): array
    {
        $groups = ClientRequest::where('status', 'open')->latest()->limit(500)->get()
            ->groupBy(fn (ClientRequest $r) => $r->topic ?? 'request-'.$r->id)
            ->map(fn ($group, $topic) => [
                'request_id' => $group->first()->id,
                'topic' => str_starts_with($topic, 'request-') ? null : $topic,
                'times_asked' => $group->count(),
                'examples' => $group->pluck('question')->unique()->take(5)->map(fn ($q) => self::maskPhones(Str::limit($q, 300)))->values()->all(),
                'channels' => $group->pluck('channel')->unique()->values()->all(),
                'last_asked' => $group->max('created_at')?->toIso8601String(),
            ])
            ->sortByDesc('times_asked')->take($this->int($args, 'limit', 30, 1, 100))->values()->all();

        return ['open_questions' => $groups, 'hint' => 'Answer a group with save_knowledge_entry, then resolve_open_questions.'];
    }

    private function listKnowledge(array $args): array
    {
        $type = in_array($args['type'] ?? null, array_keys(KnowledgeEntry::TYPES), true) ? $args['type'] : null;
        $query = mb_strtolower($this->str($args, 'query', 200));

        $entries = KnowledgeEntry::query()->when($type, fn ($q) => $q->where('type', $type))->orderBy('type')->orderBy('sort_order')->get()
            ->filter(fn (KnowledgeEntry $e) => $query === '' || str_contains(mb_strtolower(implode(' ', [...$e->getTranslations('question'), ...$e->getTranslations('answer'), (string) $e->keywords])), $query))
            ->take($this->int($args, 'limit', 50, 1, 100))
            ->map(fn (KnowledgeEntry $e) => array_filter([
                'id' => $e->id, 'type' => $e->type, 'active' => $e->is_active,
                'question' => $e->getTranslations('question'), 'answer' => $e->getTranslations('answer'),
                'keywords' => $e->keywords, 'link' => $e->link,
            ], fn ($v) => $v !== null && $v !== '' && $v !== []))->values()->all();

        return ['entries' => $entries, 'types' => KnowledgeEntry::TYPES];
    }

    private function saveKnowledge(array $args): array
    {
        $type = $args['type'] ?? null;
        if (! in_array($type, array_keys(KnowledgeEntry::TYPES), true)) {
            throw new ToolError('type must be one of: '.implode(', ', array_keys(KnowledgeEntry::TYPES)).'.');
        }
        $question = $this->tri($args, 'question', 300);
        $answer = $this->tri($args, 'answer', 3000);
        foreach ($answer as $text) {
            if (stripos($text, '[TO FILL') !== false) {
                throw new ToolError('Replace every [TO FILL: …] placeholder with real information first.');
            }
        }
        $link = $this->str($args, 'link', 255);
        if ($link !== '' && (! filter_var($link, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $link))) {
            throw new ToolError('link must be a full http(s) URL.');
        }

        $data = ['type' => $type, 'question' => $question, 'answer' => $answer, 'keywords' => $this->str($args, 'keywords', 255) ?: null, 'link' => $link ?: null];

        if (isset($args['id'])) {
            $entry = KnowledgeEntry::find($this->int($args, 'id', 0, 0, PHP_INT_MAX));
            if (! $entry) {
                throw new ToolError('No knowledge entry with this id.');
            }
            $entry->update($data); // keeps its current active / draft state
        } else {
            $entry = KnowledgeEntry::create($data + ['is_active' => $this->settings->autopublish, 'sort_order' => 0]);
        }

        return ['id' => $entry->id, 'active' => $entry->is_active, 'message' => $entry->is_active
            ? 'Saved; the chat assistant uses it now.'
            : 'Saved as a draft. An admin activates it in Admin → AI Assistant → Knowledge base.'];
    }

    private function resolve(array $args): array
    {
        $request = ClientRequest::find($this->int($args, 'request_id', 0, 0, PHP_INT_MAX));
        $entry = KnowledgeEntry::find($this->int($args, 'knowledge_id', 0, 0, PHP_INT_MAX));
        if (! $request || $request->status !== 'open') {
            throw new ToolError('No open question with this request_id.');
        }
        if (! $entry || ! $entry->is_active) {
            throw new ToolError('The knowledge entry must exist and be active (drafts must be approved by an admin first).');
        }

        return ['resolved' => app(ClientRequestResolver::class)->resolveGroup($request, $entry)];
    }

    private function aiRules(): array
    {
        return ['documents' => AiInstruction::query()->orderBy('kind')->orderBy('sort_order')->get()->map(fn (AiInstruction $i) => [
            'id' => $i->id, 'title' => $i->title, 'kind' => $i->kind, 'channel' => $i->channel, 'active' => (bool) $i->is_active,
            'content' => Str::limit((string) $i->content, 8000),
        ])->values()->all(), 'kinds' => AiInstruction::KINDS];
    }

    private function addNote(array $args): array
    {
        $kind = $args['kind'] ?? null;
        if (! in_array($kind, array_keys(AiInstruction::KINDS), true)) {
            throw new ToolError('kind must be rules or info.');
        }
        $channel = in_array($args['channel'] ?? 'all', array_keys(AiInstruction::CHANNELS), true) ? ($args['channel'] ?? 'all') : 'all';
        $title = $this->str($args, 'title', 120);
        $content = $this->str($args, 'content', AiInstruction::MAX_LENGTH);
        if ($title === '' || $content === '') {
            throw new ToolError('title and content are required.');
        }

        $note = AiInstruction::create(['kind' => $kind, 'channel' => $channel, 'title' => $title, 'content' => $content,
            'is_active' => $this->settings->autopublish, 'sort_order' => 100]);

        return ['id' => $note->id, 'active' => (bool) $note->is_active, 'message' => $note->is_active
            ? 'Added; the chat assistant follows it now.'
            : 'Added as a draft. An admin activates it in Admin → AI Assistant → AI rules & info.'];
    }

    // ---------------------------------------------------------------------------

    private function customer(ChatConversation $c): ?string
    {
        return $c->title ?? ($c->channel === 'telegram' ? $c->telegramUser?->displayName() : null)
            ?? ['web' => 'Website visitor', 'business' => 'Premium account chat', 'group' => 'Group', 'telegram' => 'Telegram user'][$c->channel] ?? null;
    }

    /** Phone numbers never leave the CMS through MCP (customers type them during registration). */
    public static function maskPhones(string $text): string
    {
        return preg_replace('/\+?\d[\d\s().\-]{6,}\d/u', '[phone hidden]', $text) ?? $text;
    }

    /** @return array{uz: string, ru: string, en: string} */
    private function tri(array $args, string $key, int $max): array
    {
        $value = $args[$key] ?? null;
        if (! is_array($value)) {
            throw new ToolError("{$key} must be an object with uz, ru and en texts.");
        }
        $out = [];
        foreach (['uz', 'ru', 'en'] as $l) {
            if (! is_string($value[$l] ?? null) || trim($value[$l]) === '') {
                throw new ToolError("{$key}.{$l} is required.");
            }
            if (mb_strlen($value[$l]) > $max) {
                throw new ToolError("{$key}.{$l} is longer than {$max} characters.");
            }
            $out[$l] = trim($value[$l]);
        }

        return $out;
    }

    private function str(array $args, string $key, int $max): string
    {
        $v = $args[$key] ?? '';
        if (! is_string($v)) {
            throw new ToolError("{$key} must be a string.");
        }

        return mb_substr(trim($v), 0, $max);
    }

    private function int(array $args, string $key, int $default, int $min, int $max): int
    {
        $v = $args[$key] ?? $default;

        return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
    }
}
