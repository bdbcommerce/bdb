@extends('admin.layout')
@section('title', 'Sales assistant')
@section('heading', 'Sales assistant — Settings')

@php
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    $days = array_map('intval', (array) old('work_days', $settings->workDays()));
    $num = fn (string $name, string $label, string $hint, int $min, int $max) => compact('name', 'label', 'hint', 'min', 'max');
@endphp

@section('content')
<div class="w-full space-y-6">
    <div class="bg-white border border-slate-200 rounded-xl p-6 text-sm text-slate-600 space-y-2">
        <p><b class="text-slate-900">What it does.</b> The sales assistant talks with new and returning customers step by step, in the language they start in: it understands their business and what they need, asks the questions for each product (<a href="{{ route('admin.sales-knowledge.index') }}" class="underline">Knowledge</a>), collects name, surname, phone and city, sends a summary to confirm and hands the request to a manager. It never gives prices, stock or delivery dates and never invents facts.</p>
        <p>In the <b>bot chat</b> it answers at once. In <b>Premium-account (Business) chats</b> it waits for a manager first during working hours and stops as soon as a manager writes. Outside working hours it follows the setting below (by default: the working hours once, then silence).</p>
        <p>It answers only from <a href="{{ route('admin.sales-knowledge.index') }}" class="underline">Knowledge</a> and the website's own data (company, brands, industries, knowledge base, articles). When it does not know, it stays <b>silent</b> and the question goes to the Telegram group set as the source chat in Invoice parser → Settings. Confirmed requests go to the sales chat and Chat leads.</p>
    </div>

    <form method="POST" action="{{ route('admin.sales-assistant.update') }}" class="bg-white border border-slate-200 rounded-xl p-6 space-y-6">
        @csrf @method('PUT')

        <div class="grid gap-5 sm:grid-cols-2">
            @foreach (['bot_mode' => 'Telegram bot chat', 'business_mode' => 'Premium-account (Business) chats'] as $field => $label)
                <div>
                    <label for="{{ $field }}" class="block text-sm font-medium text-slate-700 mb-1">{{ $label }}</label>
                    <select id="{{ $field }}" name="{{ $field }}" class="{{ $input }}">
                        @foreach (\App\Models\SalesAssistantSetting::MODES as $value => $name)
                            <option value="{{ $value }}" @selected(old($field, $settings->{$field}) === $value)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error($field)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium text-slate-700">Working hours (Tashkent time)</legend>
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                @foreach (\App\Models\SalesAssistantSetting::DAYS as $d => $name)
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="work_days[]" value="{{ $d }}" @checked(in_array($d, $days, true)) class="rounded border-slate-300"> {{ $name }}
                    </label>
                @endforeach
            </div>
            @error('work_days')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            <div class="flex flex-wrap items-center gap-2 text-sm text-slate-700">
                <label for="work_start" class="sr-only">Start</label>
                <input id="work_start" type="time" name="work_start" value="{{ old('work_start', $settings->work_start) }}" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                <span>–</span>
                <label for="work_end" class="sr-only">End</label>
                <input id="work_end" type="time" name="work_end" value="{{ old('work_end', $settings->work_end) }}" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
            </div>
            @error('work_start')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            @error('work_end')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            <div class="max-w-xl">
                <label for="off_hours_mode" class="block text-sm font-medium text-slate-700 mb-1">Outside working hours</label>
                <select id="off_hours_mode" name="off_hours_mode" class="{{ $input }}">
                    @foreach (\App\Models\SalesAssistantSetting::OFF_HOURS_MODES as $value => $name)
                        <option value="{{ $value }}" @selected(old('off_hours_mode', $settings->offHoursMode()) === $value)>{{ $name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-400 mt-1">The note goes once per closed period, in the customer's language — e.g. “{{ $settings->offHoursNote('en') }}” Later messages get no reply; they stay in Waiting for reply for the managers.</p>
                @error('off_hours_mode')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </fieldset>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                $num('wait_minutes', 'Wait for a manager (minutes)', 'Business chats, working hours: the assistant answers when no manager has answered the customer in this time.', 0, 120),
                $num('remind_after_hours', 'Reminder after (hours)', 'One friendly reminder when the customer stops answering mid-way (working hours only).', 1, 48),
                $num('close_after_hours', 'Close incomplete after (hours)', 'The request closes as incomplete; the manager still gets what was collected.', 1, 168),
                $num('follow_up_days', 'Offer again after (days)', 'When a customer cancelled but agreed to hear from us later.', 1, 90),
                $num('expert_remind_minutes', 'Expert reminder after (minutes)', 'An expert who has not answered the AI\'s question gets one reminder (working time only).', 5, 480) + ['value' => $settings->expertRemindMinutes()],
                $num('expert_escalate_minutes', 'To the main admin after (minutes)', 'Still no answer: the question goes to the main admin (working time only).', 10, 960) + ['value' => $settings->expertEscalateMinutes()],
                $num('lead_remind_minutes', 'Request not taken — remind after (minutes)', 'Nobody tapped "I take it" (or marked it handled): the main admin is reminded in the bot (working time only).', 5, 480) + ['value' => $settings->leadRemindMinutes()],
                $num('draft_nudge_days', 'Draft order reminder after (days)', 'A customer left a draft order: the AI writes once to continue it (working hours). 0 = off.', 0, 60) + ['value' => $settings->draftNudgeDays()],
                $num('large_order_sum', 'Large order from (sum)', 'Catalog prices × quantity: from this total the AI confirms the request and hands it to a manager at once. 0 = off.', 0, 100000000000) + ['value' => $settings->largeOrderSum()],
            ] as $f)
                <div>
                    <label for="{{ $f['name'] }}" class="block text-sm font-medium text-slate-700 mb-1">{{ $f['label'] }}</label>
                    <input id="{{ $f['name'] }}" type="number" name="{{ $f['name'] }}" min="{{ $f['min'] }}" max="{{ $f['max'] }}" value="{{ old($f['name'], $f['value'] ?? $settings->{$f['name']}) }}" class="{{ $input }}">
                    <p class="text-xs text-slate-400 mt-1">{{ $f['hint'] }}</p>
                    @error($f['name'])<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="model" class="block text-sm font-medium text-slate-700 mb-1">AI model</label>
                <select id="model" name="model" class="{{ $input }}">
                    @foreach (\App\Models\SalesAssistantSetting::MODELS as $value => $name)
                        <option value="{{ $value }}" @selected(old('model', $settings->model) === $value)>{{ $name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-400 mt-1">Uses ANTHROPIC_API_KEY. Every customer message is a paid request.</p>
                @error('model')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="employee_ids" class="block text-sm font-medium text-slate-700 mb-1">More employees (Telegram IDs or @usernames)</label>
                <x-admin.chat-picker name="employee_ids" kind="people" separator="newline" rows="3" maxlength="3000" :value="old('employee_ids', $settings->employee_ids)" placeholder="123456789 or @aziz_sales — one per line" :class="$input" />
                <p class="text-xs text-slate-400 mt-1">For staff who never wrote to the bot. Others: set the type “Employee” on their page in Telegram → Users.</p>
                @error('employee_ids')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <fieldset class="border-t border-slate-100 pt-6 space-y-4">
            <legend class="text-sm font-semibold text-slate-900">Assignment &amp; greeting</legend>
            <input type="hidden" name="greeting_section" value="1">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="require_assignment" class="block text-sm font-medium text-slate-700 mb-1">Talk only with assigned clients</label>
                    <select id="require_assignment" name="require_assignment" class="{{ $input }}">
                        <option value="1" @selected((string) old('require_assignment', (int) $settings->requiresAssignment()) === '1')>On — others get the greeting once, then silence</option>
                        <option value="0" @selected((string) old('require_assignment', (int) $settings->requiresAssignment()) === '0')>Off — talk with everyone except employees</option>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Set the type on the contact's page (Telegram → Users → Not assigned). The sales chat is asked once for every new contact.</p>
                </div>
                <div>
                    <label for="new_contact_mode" class="block text-sm font-medium text-slate-700 mb-1">New contact</label>
                    @php $newMode = (string) old('new_contact_mode', $settings->newContactMode()); @endphp
                    <select id="new_contact_mode" name="new_contact_mode" class="{{ $input }}">
                        @foreach (\App\Models\SalesAssistantSetting::NEW_CONTACT_MODES as $value => [$label])
                            <option value="{{ $value }}" @selected($newMode === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <ul class="text-xs text-slate-400 mt-1 space-y-0.5">
                        @foreach (\App\Models\SalesAssistantSetting::NEW_CONTACT_MODES as [$label, $help])
                            <li><span class="font-medium text-slate-500">{{ $label }}:</span> {{ $help }}</li>
                        @endforeach
                    </ul>
                    @error('new_contact_mode')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <fieldset>
                    <legend class="block text-sm font-medium text-slate-700 mb-1">Greet new contacts in</legend>
                    @php $greetIn = (array) old('greeting_channels', $settings->greetingChannels()); @endphp
                    @foreach (\App\Models\SalesAssistantSetting::GREETING_CHANNELS as $value => $label)
                        <label class="flex items-center gap-2 text-sm text-slate-700 py-0.5">
                            <input type="checkbox" name="greeting_channels[]" value="{{ $value }}" @checked(in_array($value, $greetIn, true)) class="rounded border-slate-300"> {{ $label }}
                        </label>
                    @endforeach
                    <p class="text-xs text-slate-400 mt-1">Premium-account chats include your personal contacts: untick it if the account is also used privately.</p>
                </fieldset>
            </div>
            <div>
                <label for="greeting_prompt" class="block text-sm font-medium text-slate-700 mb-1">Greeting prompt</label>
                <textarea id="greeting_prompt" name="greeting_prompt" rows="9" maxlength="8000" class="{{ $input }} font-mono text-xs">{{ old('greeting_prompt', $settings->greetingPromptText()) }}</textarea>
                <p class="text-xs text-slate-400 mt-1">How the AI greets a contact who is not assigned yet (and suppliers): one message in their language that presents the company. Empty = the default text. If the message continues an earlier talk the AI cannot see, it stays silent.</p>
                @error('greeting_prompt')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="max-w-md">
                <label for="employee_roles" class="block text-sm font-medium text-slate-700 mb-1">Employee roles (one per line)</label>
                <textarea id="employee_roles" name="employee_roles" rows="6" maxlength="2000" class="{{ $input }}">{{ old('employee_roles', implode("\n", $settings->employeeRoles())) }}</textarea>
                <p class="text-xs text-slate-400 mt-1">Offered on an employee's page (Sales, Support, Finance, CEO…).</p>
                @error('employee_roles')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </fieldset>

        <div class="border-t border-slate-100 pt-6">
            <input type="hidden" name="reorder_hints" value="0">
            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" name="reorder_hints" value="1" @checked(old('reorder_hints', $settings->reorder_hints)) class="mt-0.5 rounded border-slate-300">
                <span><span class="font-medium">Reorder hints to the sales chat</span>
                    <span class="block text-xs text-slate-400">Once a day: "Ali usually orders ribbons about every 30 days — last order 36 days ago" (from the order history; a manager decides to write).</span></span>
            </label>
        </div>

        <fieldset class="border-t border-slate-100 pt-6 space-y-3">
            <legend class="text-sm font-semibold text-slate-900">Data sent to Claude</legend>
            <input type="hidden" name="claude_data_section" value="1">
            <p class="text-xs text-slate-500">Every customer message the AI answers is one request to the Claude API (Anthropic). It always contains the conversation, the prompts, the knowledge, the website data and the catalog prices this customer may see. Untick what should stay on our server. The weekly and daily sales reports always hide phones; experts never see them.</p>
            @foreach (\App\Models\SalesAssistantSetting::CLAUDE_DATA as $key => [$label, $help])
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="claude_data[{{ $key }}]" value="1" @checked(old('claude_data_section') ? old('claude_data.'.$key) : $settings->sendsToClaude($key)) class="mt-0.5 rounded border-slate-300">
                    <span><span class="font-medium">{{ $label }}</span><span class="block text-xs text-slate-400">{{ $help }}</span></span>
                </label>
            @endforeach
        </fieldset>

        <div class="flex justify-end">
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">Save settings</button>
        </div>
    </form>

    <div class="grid gap-6">
        @foreach (['Employees — never talked to' => [$employees, 'employees']] as $title => [$list, $filter])
            <div class="bg-white border border-slate-200 rounded-xl p-6 text-sm" data-list="{{ $filter }}">
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">{{ $title }}</h2>
                @forelse ($list as $u)
                    <a href="{{ route('admin.telegram-users.show', $u) }}" class="block py-1 underline text-slate-700">{{ $u->displayName() }}</a>
                @empty
                    <p class="text-slate-500">Nobody yet. Tick it on a user's page in <a href="{{ route('admin.telegram-users.index') }}" class="underline">Telegram → Users</a>.</p>
                @endforelse
                @if ($list->isNotEmpty())
                    <a href="{{ route('admin.telegram-users.index', ['filter' => $filter]) }}" class="inline-block mt-2 text-xs text-slate-500 underline">Show in Users</a>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endsection
