<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiEmployee;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ExpertQuestion;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
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

    public const FILTERS = ['all' => 'All', 'unassigned' => 'Not assigned', 'clients' => 'Clients', 'suppliers' => 'Suppliers', 'employees' => 'Employees', 'registered' => 'Registered', 'pending' => 'Not finished registration'];

    public function index(Request $request)
    {
        $filter = is_string($f = $request->query('filter')) && array_key_exists($f, self::FILTERS) ? $f : 'all';
        $search = is_string($q = $request->query('q')) ? mb_substr(trim($q), 0, 100) : '';

        $users = TelegramUser::query()
            ->withCount('conversations')
            ->withMax('conversations as last_message_at', 'updated_at')
            ->when($filter === 'registered', fn ($q) => $q->registered())
            ->when($filter === 'pending', fn ($q) => $q->whereNull('registered_at'))
            ->when($filter === 'employees', fn ($q) => $q->where(fn ($w) => $w->where('is_employee', true)->orWhere('contact_type', TelegramUser::TYPE_EMPLOYEE)))
            ->when($filter === 'unassigned', fn ($q) => $q->unassigned())
            ->when($filter === 'clients', fn ($q) => $q->where('contact_type', TelegramUser::TYPE_CLIENT))
            ->when($filter === 'suppliers', fn ($q) => $q->where('contact_type', TelegramUser::TYPE_SUPPLIER))
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
                'employees' => TelegramUser::where(fn ($w) => $w->where('is_employee', true)->orWhere('contact_type', TelegramUser::TYPE_EMPLOYEE))->count(),
                'unassigned' => TelegramUser::unassigned()->count(),
                'clients' => TelegramUser::where('contact_type', TelegramUser::TYPE_CLIENT)->count(),
                'suppliers' => TelegramUser::where('contact_type', TelegramUser::TYPE_SUPPLIER)->count(),
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
            // Premium-account chats: can the bot write there (Telegram Business "reply" permission)?
            'businessCanReply' => ($bizId = $conversations->map(fn (ChatConversation $c) => $c->viaConnectionId())->filter()->first())
                ? (bool) TelegramBusinessConnection::where('connection_id', $bizId)->value('can_reply')
                : null,
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
        if ($request->has('phone')) {
            // Local Uzbek numbers (9 digits) get +998; others must include the country code.
            $phone = preg_replace('/[\s()\-]/', '', (string) $request->input('phone'));
            $request->merge(['phone' => $phone === '' ? null : (preg_match('/^\d{9}$/', $phone) ? '+998'.$phone : $phone)]);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'regex:/^(\+[0-9]{7,15}|[0-9]{11,15})$/'],
            'company' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'ask_registration' => ['sometimes', 'boolean'],
            'is_employee' => ['sometimes', 'boolean'],
            'contact_type' => ['sometimes', 'nullable', Rule::in(array_keys(TelegramUser::TYPES))],
            'employee_role' => ['sometimes', 'nullable', 'string', 'max:60'],
            'answers_questions' => ['sometimes', 'boolean'],
            'is_main_admin' => ['sometimes', 'boolean'],
            'ai_employee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ai_employees', 'id')->where(fn ($q) => $q->whereIn('kind', AiEmployee::RUNNABLE_KINDS))],
            'locale' => ['nullable', Rule::in(['uz', 'ru', 'en'])],
        ], ['phone.regex' => 'Enter the phone with the country code, e.g. +998901234567 (or 9 digits like 90 123 45 67 for Uzbekistan).']);

        if (isset($data['phone']) && ! str_starts_with($data['phone'], '+')) {
            $data['phone'] = '+'.$data['phone'];
        }
        $data['ask_registration'] = $request->boolean('ask_registration') && ! $telegramUser->isRegistered();
        // Who the contact is: the type select (the older "Employee" tick alone still works).
        if ($request->has('contact_type')) {
            $data['contact_type'] = $data['contact_type'] ?? null;
            $data['is_employee'] = $data['contact_type'] === TelegramUser::TYPE_EMPLOYEE;
        } else {
            $data['is_employee'] = $request->boolean('is_employee');
            if ($data['is_employee']) {
                $data['contact_type'] = TelegramUser::TYPE_EMPLOYEE;
            } elseif ($telegramUser->contact_type === TelegramUser::TYPE_EMPLOYEE) {
                $data['contact_type'] = TelegramUser::TYPE_CLIENT;
            }
        }
        if (array_key_exists('contact_type', $data)) {
            if ($data['contact_type'] !== $telegramUser->contact_type) {
                $data['assigned_at'] = $data['contact_type'] ? now() : null;
            }
            if ($data['contact_type'] !== TelegramUser::TYPE_EMPLOYEE) {
                $data['employee_role'] = null;
            }
            if ($data['contact_type'] !== TelegramUser::TYPE_CLIENT) {
                $data['ai_employee_id'] = null;
            }
        }
        // Answering the AI's questions (and being the main admin) is for employees only; there is one main admin.
        if ($request->has('contact_type') || $request->has('is_employee')) {
            $employee = ($data['contact_type'] ?? $telegramUser->contact_type) === TelegramUser::TYPE_EMPLOYEE;
            $data['answers_questions'] = $employee && $request->boolean('answers_questions');
            $data['is_main_admin'] = $employee && $request->boolean('is_main_admin');
        } else {
            unset($data['answers_questions'], $data['is_main_admin']); // only the assignment form sets them
        }
        if (isset($data['employee_role']) && ! in_array($data['employee_role'], SalesAssistantSetting::current()->employeeRoles(), true)) {
            return back()->withInput()->withErrors(['employee_role' => 'Pick a role from the list (Sales assistant → Settings → Employee roles).']);
        }
        // Ticking or unticking starts the questions over: a step left from an abandoned attempt must
        // not turn the customer's next message into their name.
        if (! config('services.telegram.replies') && $data['ask_registration'] !== $telegramUser->ask_registration && ! $telegramUser->isRegistered()) {
            $data['step'] = TelegramUser::STEP_LANGUAGE;
        }

        // A phone entered or changed by an admin is trusted; clearing it removes the verification.
        if (array_key_exists('phone', $data) && $data['phone'] !== $telegramUser->phone) {
            $data['phone_verified_at'] = $data['phone'] ? now() : null;
        }

        $telegramUser->update($data);
        if ($telegramUser->is_main_admin) {
            TelegramUser::where('is_main_admin', true)->whereKeyNot($telegramUser->id)->update(['is_main_admin' => false]);
        }
        // No longer an expert (also a main admin replaced just now): their open questions go back to the queue.
        foreach (ExpertQuestion::where('status', 'asked')->with('expert')->get() as $held) {
            if (! $held->expert?->isExpert()) {
                $held->update(['status' => 'queued', 'expert_id' => null, 'pending' => null, 'asked_at' => null]);
            }
        }

        return redirect()->route('admin.telegram-users.show', $telegramUser)->with('success', $data['ask_registration']
            ? 'Saved. The bot will ask for this customer\'s details the next time they write (bot chat or Premium-account chat).'
            : 'Saved.');
    }
}
