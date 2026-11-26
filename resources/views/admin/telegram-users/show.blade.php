@extends('admin.layout')
@section('title', $user->displayName() ?? 'Telegram user')
@section('heading', 'Telegram user')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <a href="{{ route('admin.telegram-users.index') }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; All Telegram users</a>
    @if($replyTo)
        <a href="{{ route('admin.chat-conversations.show', $replyTo) }}#reply-message"
           class="inline-flex items-center gap-2 bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
            </svg>
            Message in Telegram
        </a>
    @endif
</div>

<div class="grid gap-4 lg:grid-cols-3">
    {{-- Info --}}
    <aside class="bg-white border border-slate-200 rounded-xl p-5 h-fit lg:order-2">
        <div class="flex items-center gap-3 mb-4">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full border border-slate-300 text-sm font-semibold text-slate-600 uppercase" aria-hidden="true">
                {{ mb_substr($user->displayName() ?? '?', 0, 1) }}
            </span>
            <div class="min-w-0">
                <p class="text-base font-semibold text-slate-900 truncate">{{ $user->displayName() ?? 'No name yet' }}</p>
                <p class="text-[11px] font-medium mt-0.5 inline-block rounded-full px-2 py-0.5 {{ $user->isRegistered() ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-500' }}">
                    {{ $user->isRegistered() ? 'Registered' : ($user->step === \App\Models\TelegramUser::STEP_GUEST ? 'Not registered — asks questions, invited to register' : (config('services.telegram.replies') ? 'Registration not finished (step: '.$user->step.')' : ($user->ask_registration ? 'Will be asked to register' : 'Not registered'))) }}
                </p>
            </div>
        </div>
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-400">Phone</dt>
                <dd class="text-slate-800">
                    @if($user->phone)
                        <a href="tel:{{ $user->phone }}" class="underline">{{ $user->phone }}</a>
                        <span class="text-xs {{ $user->hasVerifiedPhone() ? 'text-slate-500' : 'text-amber-700' }}">{{ $user->hasVerifiedPhone() ? '· verified' : '· typed, not verified (asked to share it before a call request)' }}</span>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Telegram</dt>
                <dd class="text-slate-800">
                    @if($user->username)
                        <a href="https://t.me/{{ $user->username }}" target="_blank" rel="noopener" class="underline">{{ '@'.$user->username }}</a>
                    @else
                        No @username
                    @endif
                    <span class="block text-xs text-slate-400">ID {{ $user->telegram_id }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Language</dt>
                <dd class="text-slate-800 uppercase">{{ $user->locale ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">First message</dt>
                <dd class="text-slate-800">{{ $user->created_at->format('d.m.Y H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Registered</dt>
                <dd class="text-slate-800">{{ $user->registered_at?->format('d.m.Y H:i') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Messages / questions / unanswered</dt>
                <dd class="text-slate-800">{{ $stats['messages'] }} / {{ $stats['questions'] }} / {{ $stats['unanswered'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Callback requests</dt>
                <dd class="text-slate-800">
                    @forelse($leads as $lead)
                        @if($leadConversations[$lead->id] ?? null)
                            <a href="{{ route('admin.chat-conversations.show', $leadConversations[$lead->id]) }}" class="block underline">{{ $lead->created_at->format('d.m.Y H:i') }} · {{ $lead->phone }}</a>
                        @else
                            <span class="block">{{ $lead->created_at->format('d.m.Y H:i') }} · {{ $lead->phone }}</span>
                        @endif
                    @empty
                        None
                    @endforelse
                </dd>
            </div>
            @if($user->company || $user->notes)
                <div>
                    <dt class="text-xs text-slate-400">Company / notes</dt>
                    <dd class="text-slate-800 whitespace-pre-line break-words">{{ $user->company }}@if($user->company && $user->notes)<br>@endif{{ $user->notes }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs text-slate-400">First seen via</dt>
                <dd class="text-slate-800">{{ \App\Models\TelegramUser::SOURCES[$user->source] ?? 'Bot' }}</dd>
            </div>
        </dl>

        {{-- Admin-entered details --}}
        <form method="POST" action="{{ route('admin.telegram-users.update', $user) }}" class="mt-5 pt-5 border-t border-slate-100 space-y-3"
              x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
            @csrf @method('PUT')
            <button type="button" @click="open = ! open" :aria-expanded="open.toString()"
                    class="flex w-full items-center justify-between text-sm font-medium text-slate-900">
                Edit details
                <svg class="w-4 h-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                </svg>
            </button>
            <div x-show="open" x-cloak class="space-y-3">
                @php $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400'; @endphp
                <div>
                    <label for="tg-name" class="block text-xs font-medium text-slate-600 mb-1">Name</label>
                    <input id="tg-name" name="name" maxlength="60" value="{{ old('name', $user->name) }}" placeholder="{{ $user->profile_name }}" class="{{ $input }}">
                    @error('name')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="tg-phone" class="block text-xs font-medium text-slate-600 mb-1">Mobile phone</label>
                    <input id="tg-phone" name="phone" type="tel" inputmode="tel" maxlength="20" value="{{ old('phone', $user->phone) }}" placeholder="+998 90 123 45 67" class="{{ $input }}">
                    @error('phone')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                    @php $priceGroup = rescue(fn () => \App\Models\CatalogClient::priceGroupFor($user), 'end_user', false); @endphp
                    @if ($priceGroup !== 'end_user')
                        <p class="text-xs mt-1 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 px-3 py-2">
                            Catalog prices: <b>{{ $priceGroup === 'partner' ? 'Partner' : 'Dealer' }}</b> — this phone is in the 1C list, so the AI quotes {{ $priceGroup }} prices to this contact. Changing the phone here changes that.
                        </p>
                    @else
                        <p class="text-xs text-slate-400 mt-1">Catalog prices: end user{{ $user->phone && ! $user->hasVerifiedPhone() ? ' (the phone is not verified, so dealer/partner prices never apply)' : '' }}. A phone you enter here is trusted.</p>
                    @endif
                </div>
                <div>
                    <label for="tg-company" class="block text-xs font-medium text-slate-600 mb-1">Company</label>
                    <input id="tg-company" name="company" maxlength="120" value="{{ old('company', $user->company) }}" class="{{ $input }}">
                    @error('company')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="tg-locale" class="block text-xs font-medium text-slate-600 mb-1">Language</label>
                    <select id="tg-locale" name="locale" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['uz' => 'Oʻzbekcha', 'ru' => 'Русский', 'en' => 'English'] as $code => $label)
                            <option value="{{ $code }}" @selected(old('locale', $user->locale) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="tg-notes" class="block text-xs font-medium text-slate-600 mb-1">Notes</label>
                    <textarea id="tg-notes" name="notes" rows="3" maxlength="2000" class="{{ $input }}">{{ old('notes', $user->notes) }}</textarea>
                    @error('notes')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                </div>
                @unless($user->isRegistered())
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="hidden" name="ask_registration" value="0">
                        <input type="checkbox" name="ask_registration" value="1" @checked(old('ask_registration', $user->ask_registration))
                               class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                        <span>
                            Ask for registration info on the next conversation
                            <span class="block text-xs text-slate-400">The next time the customer writes — to the bot or to your Premium account — the bot asks for their name and phone (in the bot chat also the language). In Premium-account chats it writes on behalf of your account.</span>
                            @if($businessCanReply === false)
                                <span class="block text-xs text-red-700 mt-1">The Premium account does not let the bot reply: in Telegram → Settings → Telegram Business → Chatbots, allow it to reply to messages.</span>
                            @endif
                        </span>
                    </label>
                @endunless
                @php
                    $aiSettings = \App\Models\SalesAssistantSetting::current();
                    $suggested = $user->contact_type === null ? $user->suggestedType() : null;
                    $type = (string) old('contact_type', $user->contact_type ?? '');
                    $agents = \App\Models\AiEmployee::whereIn('kind', \App\Models\AiEmployee::RUNNABLE_KINDS)->orderBy('sort')->orderBy('name')->get();
                @endphp
                <div id="assign" class="border-t border-slate-100 pt-3 space-y-3 scroll-mt-4" x-data="{ type: @js($type) }">
                    <p class="text-xs font-medium text-slate-600">Assignment — who this contact is</p>
                    @if ($user->contact_type === null)
                        <p class="text-xs rounded-lg border border-amber-300 bg-amber-50 text-amber-800 px-3 py-2">
                            Not assigned yet: the AI {{ $aiSettings->requiresAssignment() ? 'only greets them once and waits' : 'talks to them (assignment is not required in the settings)' }}.
                            @if ($suggested)<br><b>Suggested:</b> {{ \App\Models\TelegramUser::TYPES[$suggested[0]] }} — {{ $suggested[1] }}. <button type="button" @click="type = @js($suggested[0])" class="underline font-medium">Use it</button>, then Save.@endif
                        </p>
                    @endif
                    <div>
                        <label for="tg-type" class="block text-xs font-medium text-slate-600 mb-1">Type</label>
                        <select id="tg-type" name="contact_type" x-model="type" class="{{ $input }}">
                            <option value="">Not assigned</option>
                            @foreach (\App\Models\TelegramUser::TYPES as $value => $label)
                                <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Client: the AI team talks with them. Supplier: greeting only. Employee: the AI never writes to them.</p>
                        @error('contact_type')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div x-show="type === 'employee'" x-cloak>
                        <label for="tg-role" class="block text-xs font-medium text-slate-600 mb-1">Employee role</label>
                        <select id="tg-role" name="employee_role" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach ($aiSettings->employeeRoles() as $role)
                                <option value="{{ $role }}" @selected(old('employee_role', $user->employee_role) === $role)>{{ $role }}</option>
                            @endforeach
                        </select>
                        @error('employee_role')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                        <label class="flex items-start gap-2 mt-3 text-sm text-slate-700">
                            <input type="hidden" name="answers_questions" value="0">
                            <input type="checkbox" name="answers_questions" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('answers_questions', $user->answers_questions))>
                            <span>Answers AI questions <span class="block text-xs text-slate-400">The AI sends questions of this role (department) here, one at a time, in the bot chat.</span></span>
                        </label>
                        <label class="flex items-start gap-2 mt-2 text-sm text-slate-700">
                            <input type="hidden" name="is_main_admin" value="0">
                            <input type="checkbox" name="is_main_admin" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('is_main_admin', $user->is_main_admin))>
                            <span>Main admin <span class="block text-xs text-slate-400">Gets questions nobody answered in time, transferred 3 times, or with no department. Only one person.</span></span>
                        </label>
                        @if ($user->answers_questions || $user->is_main_admin)
                            <p class="text-xs mt-2 {{ $user->bot_chat_at ? 'text-slate-500' : 'rounded-lg border border-amber-300 bg-amber-50 text-amber-800 px-3 py-2' }}">
                                {{ $user->bot_chat_at ? 'Bot chat started '.$user->bot_chat_at->setTimezone('Asia/Tashkent')->format('d.m.Y') : 'Has not written to the bot yet: ask them to open the bot and press /start, or questions cannot reach them.' }}
                            </p>
                        @endif
                    </div>
                    <div x-show="type === 'client'" x-cloak>
                        <label for="tg-agent" class="block text-xs font-medium text-slate-600 mb-1">AI agent</label>
                        <select id="tg-agent" name="ai_employee_id" class="{{ $input }}">
                            <option value="">Automatic (by role)</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->id }}" @selected((string) old('ai_employee_id', $user->ai_employee_id) === (string) $agent->id)>{{ $agent->name }} — {{ $agent->role }} ({{ \App\Models\AiEmployee::STATUSES[$agent->status] ?? $agent->status }})</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Answers this client while the chat is with that agent's role; a draft agent never answers.</p>
                        @error('ai_employee_id')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                    </div>
                    @if ($user->greeted_at)<p class="text-xs text-slate-400">Greeted {{ $user->greeted_at->setTimezone('Asia/Tashkent')->format('d.m.Y H:i') }}.</p>@endif
                </div>
                <button type="submit" class="w-full bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">Save</button>
            </div>
        </form>

        @php $portrait = \App\Models\ClientPerson::current($user); @endphp
        <section class="bg-white border border-slate-200 rounded-xl p-4 space-y-2 text-sm" aria-labelledby="portrait-h">
            <h2 id="portrait-h" class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Client portrait</h2>
            @if ($portrait)
                <p><a href="{{ route('admin.client-people.edit', $portrait) }}" class="font-medium text-slate-900 underline">{{ $portrait->name }}</a>
                    <span class="text-slate-600">{{ collect([$portrait->position, $portrait->part ? \App\Models\ClientPerson::PARTS[$portrait->part] ?? null : null])->filter()->implode(' · ') }}</span></p>
                @if ($portrait->client)<p class="text-slate-600">at <a href="{{ route('admin.clients.show', $portrait->client) }}" class="underline">{{ $portrait->client->name }}</a></p>@endif
                <p class="text-xs text-slate-500">{{ \App\Models\ClientPerson::STATUSES[$portrait->status] ?? $portrait->status }} · the AI team reads this portrait.</p>
            @else
                <p class="text-slate-600">No portrait yet.</p>
                <a href="{{ route('admin.client-people.create', ['telegram_user' => $user->id]) }}" class="inline-block text-sm rounded-lg border border-slate-300 px-3 py-1.5 hover:border-slate-500">Create a portrait (filled in from the chat)</a>
            @endif
        </section>

        {{-- Orders from the chat: the open draft, the last confirmed ones (documents) and older ones per month. --}}
        @php $draftOrder = \App\Models\Order::where('telegram_user_id', $user->id)->where('status', 'draft')->latest('id')->first();
             $orders = \App\Models\Order::where('telegram_user_id', $user->id)->where('status', 'confirmed')->orderByDesc('confirmed_at')->limit(\App\Services\SalesAssistant\OrderBook::KEEP)->get();
             $months = \App\Models\OrderMonth::where('telegram_user_id', $user->id)->orderByDesc('month')->limit(24)->get(); @endphp
        <section class="bg-white border border-slate-200 rounded-xl p-4 space-y-2 text-sm" aria-labelledby="orders-h">
            <h2 id="orders-h" class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Orders</h2>
            @if ($draftOrder)
                <p><a href="{{ route('admin.orders.show', $draftOrder) }}" class="underline text-slate-900">Draft #{{ $draftOrder->id }}</a>
                    <span class="text-xs text-slate-500">{{ $draftOrder->updated_at->format('d.m.Y') }} · {{ count((array) $draftOrder->items) }} items · the AI continues it</span></p>
            @endif
            @foreach ($orders as $o)
                <p><a href="{{ route('admin.orders.show', $o) }}" class="underline text-slate-900">#{{ $o->id }} · {{ $o->confirmed_at?->format('d.m.Y') }}</a>
                    <span class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit(implode(', ', $o->itemLines()), 80) }}</span></p>
            @endforeach
            @foreach ($months as $m)
                <p class="text-slate-600">{{ $m->month }}: {{ $m->orders }} {{ \Illuminate\Support\Str::plural('order', $m->orders) }}@if ($m->quoted_sum) · ≈ {{ number_format($m->quoted_sum, 0, '', ' ') }} sum @endif
                    <span class="block text-xs text-slate-400">{{ \Illuminate\Support\Str::limit(implode(', ', array_keys((array) $m->items)), 100) }}</span></p>
            @endforeach
            @if (! $draftOrder && $orders->isEmpty() && $months->isEmpty())<p class="text-slate-600">No orders yet.</p>@endif
        </section>

        {{-- Companies this contact works with (linked by a manager, or by the AI from an INN they sent). --}}
        <section class="bg-white border border-slate-200 rounded-xl p-4 space-y-2 text-sm" aria-labelledby="companies-h">
            <h2 id="companies-h" class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Companies</h2>
            @if ($onec = \App\Models\OnecPerson::forContact($user))
                <p class="text-slate-700">1C client (private person): <span class="font-medium">{{ $onec->name }}</span>{{ $onec->code ? ' · code '.$onec->code : '' }}
                    <span class="block text-xs text-slate-500">Matched by phone {{ $onec->phone }}{{ $user->hasVerifiedPhone() ? '' : ' — the contact\'s phone is not verified' }}.</span></p>
            @endif
            @forelse ($user->companyLinks()->with('client')->get() as $link)
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.clients.show', $link->client) }}" class="font-medium text-slate-900 underline">{{ $link->client->name }}</a>
                    <span class="text-xs text-slate-500">{{ $link->client->inn ? 'INN '.$link->client->inn.' · ' : '' }}{{ $link->byManager() ? 'linked by a manager' : 'found by the AI' }}</span>
                    @unless ($link->byManager())
                        <form method="POST" action="{{ route('admin.companies.confirm-link', $link) }}">@csrf<button class="text-xs border border-slate-900 rounded px-2 py-0.5">Confirm</button></form>
                    @endunless
                    <form method="POST" action="{{ route('admin.companies.unlink', $link) }}" onsubmit="return confirm('Unlink?')">@csrf @method('DELETE')<button class="text-xs text-slate-500 underline">Unlink</button></form>
                </div>
            @empty
                <p class="text-slate-600">None yet. The AI links one when the customer sends an INN.</p>
            @endforelse
            <form method="POST" action="{{ route('admin.companies.link-contact', $user) }}" class="flex gap-2 pt-1">
                @csrf
                <label for="link-inn" class="sr-only">INN</label>
                <input id="link-inn" name="inn" inputmode="numeric" maxlength="20" placeholder="INN (9 digits)" class="w-36 rounded-lg border-slate-300 text-sm">
                <button class="text-sm rounded-lg border border-slate-300 px-3 py-1.5 hover:border-slate-500">Link company</button>
            </form>
            <form method="POST" action="{{ route('admin.companies.onec-client', $user) }}" class="pt-1">
                @csrf
                <button class="text-sm rounded-lg border border-dashed border-slate-400 px-3 py-1.5 text-slate-600 hover:border-slate-600" title="A private person without a company. Demo: the 1C method is not connected yet">Create client in 1C (private person) · demo</button>
            </form>
        </section>
    </aside>

    {{-- Conversation history --}}
    <div class="lg:col-span-2 lg:order-1 space-y-4">
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Conversation history</h2>
        @forelse($conversations as $conversation)
            <details class="group bg-white border border-slate-200 rounded-xl" @if($loop->first) open @endif
                     x-data @toggle="if ($el.open) $nextTick(() => { const b = $el.querySelector('[data-chat-scroll]'); if (b) b.scrollTop = b.scrollHeight; })">
                <summary class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                    <span class="flex items-center gap-2 text-sm font-medium text-slate-900">
                        <svg class="w-4 h-4 text-slate-400 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                        </svg>
                        {{ $conversation->created_at->format('d.m.Y H:i') }}
                        <span class="text-xs font-normal text-slate-500">· {{ $conversation->message_count }} {{ Str::plural('message', $conversation->message_count) }}</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full border border-slate-300 text-slate-600">{{ $channels[$conversation->channel] ?? $conversation->channel }}</span>
                        @if($conversation->unanswered_count)
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">{{ $conversation->unanswered_count }} unanswered</span>
                        @endif
                        @if($conversation->chat_lead_id)
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-slate-900 text-white">Lead</span>
                        @endif
                    </span>
                </summary>
                <div class="border-t border-slate-200 rounded-b-xl overflow-hidden">
                    <div class="bg-[#efeae2] px-3 sm:px-5 py-4 max-h-[32rem] overflow-y-auto" data-chat-scroll x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
                        @include('admin.chat-conversations._messages', ['messages' => $conversation->messages, 'defaultVia' => $conversation->channel === 'business' ? 'assistant' : ($conversation->channel === 'telegram' ? 'bot' : null)])
                    </div>
                    <div class="px-5 py-2.5">
                        <a href="{{ route('admin.chat-conversations.show', $conversation) }}" class="inline-block text-xs font-medium text-slate-600 underline hover:text-slate-900">Open this conversation</a>
                    </div>
                </div>
            </details>
            @if($loop->last && $total > $conversations->count())
                <p class="text-xs text-slate-500">
                    Showing the latest {{ $conversations->count() }} of {{ $total }} conversations.
                    <a href="{{ route('admin.chat-conversations.index', ['channel' => 'telegram']) }}" class="font-medium underline">All conversations</a>
                </p>
            @endif
        @empty
            <div class="bg-white border border-slate-200 rounded-xl px-6 py-12 text-center">
                <p class="text-sm font-medium text-slate-500">No messages stored</p>
                <p class="text-xs text-slate-400 mt-1">Conversations are kept for {{ \App\Models\ChatConversation::KEEP_DAYS }} days.</p>
            </div>
        @endforelse
    </div>
</div>
@endsection
