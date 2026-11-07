<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\TelegramUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Telegram customers of the bot: a searchable list, and each customer's details with their
 * whole chat history (bot chats, plus chats with the connected Premium account).
 */
class TelegramUserController extends Controller
{
    /** Conversations rendered on a user page (newest first); older ones are linked from Conversations. */
    public const SHOWN = 30;

    public const FILTERS = ['all' => 'All', 'registered' => 'Registered', 'pending' => 'Not finished registration'];

    public function index(Request $request)
    {
        $filter = is_string($f = $request->query('filter')) && array_key_exists($f, self::FILTERS) ? $f : 'all';
        $search = is_string($q = $request->query('q')) ? mb_substr(trim($q), 0, 100) : '';

        $users = TelegramUser::query()
            ->withCount('conversations')
            ->withMax('conversations as last_message_at', 'updated_at')
            ->when($filter === 'registered', fn ($q) => $q->registered())
            ->when($filter === 'pending', fn ($q) => $q->whereNull('registered_at'))
            ->when($search !== '', function ($query) use ($search) {
                // "!" escapes LIKE wildcards the same way on SQLite and MySQL (a backslash does not).
                $like = fn (string $s) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s).'%';
                $term = ltrim($search, '@');
                // SQLite ignores case only for A–Z, so Cyrillic names are also tried in lower and title case.
                $variants = array_unique([$term, mb_strtolower($term), mb_convert_case($term, MB_CASE_TITLE)]);
                $digits = preg_replace('/\D/', '', $search);

                $query->where(function ($q) use ($variants, $like, $digits) {
                    foreach ($variants as $variant) {
                        foreach (['name', 'profile_name', 'username', 'telegram_id', 'company'] as $column) {
                            $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like($variant)]);
                        }
                    }
                    if (strlen($digits) >= 3) {
                        $q->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->orderByDesc('last_message_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.telegram-users.index', [
            'users' => $users,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'search' => $search,
            'counts' => [
                'all' => TelegramUser::count(),
                'registered' => TelegramUser::registered()->count(),
                'pending' => TelegramUser::whereNull('registered_at')->count(),
            ],
        ]);
    }

    public function show(TelegramUser $telegramUser)
    {
        // Newest first; each conversation is one chat session (a new one starts after 24 h of silence).
        $total = $telegramUser->conversations()->count();
        $conversations = $telegramUser->conversations()->latest('updated_at')->latest('id')->limit(self::SHOWN)->get();

        return view('admin.telegram-users.show', [
            'user' => $telegramUser,
            'conversations' => $conversations,
            'replyTo' => $conversations->firstWhere('channel', 'telegram'),
            'total' => $total,
            'leads' => ChatLead::whereIn('id', $telegramUser->conversations()->whereNotNull('chat_lead_id')->pluck('chat_lead_id'))->latest()->get(),
            'leadConversations' => $telegramUser->conversations()->whereNotNull('chat_lead_id')->pluck('id', 'chat_lead_id'),
            'channels' => ChatConversation::CHANNELS,
            'stats' => [
                'messages' => $conversations->sum('message_count'),
                'questions' => $conversations->sum(fn (ChatConversation $c) => collect($c->textMessages())->where('role', 'user')->count()),
                'unanswered' => $conversations->sum('unanswered_count'),
            ],
        ]);
    }

    /** Admin-entered details; "ask_registration" makes the bot ask on the customer's next message. */
    public function update(Request $request, TelegramUser $telegramUser)
    {
        $request->merge(['phone' => preg_replace('/[\s()\-]/', '', (string) $request->input('phone')) ?: null]);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'company' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'ask_registration' => ['sometimes', 'boolean'],
            'locale' => ['nullable', Rule::in(['uz', 'ru', 'en'])],
        ], ['phone.regex' => 'Enter the phone as digits, e.g. +998901234567.']);

        if (isset($data['phone']) && ! str_starts_with($data['phone'], '+')) {
            $data['phone'] = '+'.$data['phone'];
        }
        $data['ask_registration'] = $request->boolean('ask_registration') && ! $telegramUser->isRegistered();

        $telegramUser->update($data);

        return redirect()->route('admin.telegram-users.show', $telegramUser)->with('success', $data['ask_registration']
            ? 'Saved. The bot will ask for language, name and phone the next time this customer writes to it.'
            : 'Saved.');
    }
}
