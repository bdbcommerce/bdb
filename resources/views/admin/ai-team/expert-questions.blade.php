@extends('admin.layout')
@section('title', 'Expert questions')
@section('heading', 'AI Sales & Support Team — Expert questions')

@php
    use App\Models\ExpertQuestion;
    use App\Models\SalesKnowledge;
    $input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-0';
    $tz = 'Asia/Tashkent';
@endphp

@section('content')
<div class="w-full space-y-6">
    <p class="text-sm text-slate-600 max-w-3xl">
        What the AI could not answer, or was not sure of, goes to an employee with <span class="font-medium text-slate-800">Answers AI questions</span>
        (Telegram → Users) in the bot chat, one question at a time. Sometimes the AI adds its own idea: the expert taps
        <span class="font-medium text-slate-800">Correct</span> or writes a fix. The customer gets the answer at once, and the answer becomes a
        knowledge draft below. The AI uses it in other chats only after you approve it.
    </p>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="bg-white border border-slate-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Who answers</p>
            @forelse ($experts as $x)
                <p class="text-sm mt-1"><a href="{{ route('admin.telegram-users.show', $x) }}" class="underline text-slate-900">{{ $x->displayName() ?? $x->telegram_id }}</a>
                    <span class="text-slate-500">{{ collect([$x->answers_questions ? $x->employee_role : null, $x->is_main_admin ? 'main admin' : null])->filter()->implode(' · ') ?: 'no role' }}</span>
                    @unless ($x->bot_chat_at)<span class="text-amber-700 text-xs">— has not pressed /start</span>@endunless</p>
            @empty
                <p class="text-sm text-slate-500 mt-1">Nobody yet: open an employee in Telegram → Users and tick “Answers AI questions”. Until then, unknown questions go to the source group as before.</p>
            @endforelse
            @if ($experts->isNotEmpty() && ! $admin)<p class="text-xs text-amber-700 mt-2">No main admin is ticked: unanswered questions are not passed on.</p>@endif
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">The AI's ideas</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $ideas['accepted'] + $ideas['fixed'] ? round(100 * $ideas['accepted'] / ($ideas['accepted'] + $ideas['fixed'])).'%' : '—' }}</p>
            <p class="text-xs text-slate-500">accepted as correct · {{ $ideas['accepted'] }} accepted, {{ $ideas['fixed'] }} fixed, {{ $ideas['written'] }} answered without an idea</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Now</p>
            <p class="mt-2 text-sm text-slate-800">{{ $counts['open'] }} open · {{ $counts['review'] }} knowledge draft(s) to approve</p>
        </div>
    </div>

    <nav class="flex flex-wrap gap-2" aria-label="Filter">
        @foreach (\App\Http\Controllers\Admin\ExpertQuestionController::FILTERS as $key => $label)
            <a href="{{ route('admin.expert-questions.index', ['filter' => $key]) }}"
               class="text-sm rounded-lg border px-3 py-1.5 {{ $filter === $key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 text-slate-700 hover:border-slate-500' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @error('knowledge')<p class="text-sm text-red-700">{{ $message }}</p>@enderror

    <div class="space-y-3">
        @forelse ($questions as $q)
            <article class="bg-white border border-slate-200 rounded-xl p-4 space-y-2 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-medium text-slate-900">#{{ $q->id }} · {{ ExpertQuestion::STATUSES[$q->status] ?? $q->status }}
                        <span class="font-normal text-slate-500">· {{ $q->department ?? 'no department' }}{{ $q->to_admin ? ' · main admin' : '' }}{{ $q->transfers ? ' · transferred '.$q->transfers.'×' : '' }}{{ $q->children_count ? ' · +'.$q->children_count.' customer(s)' : '' }}</span></p>
                    <p class="text-xs text-slate-500">{{ $q->created_at->setTimezone($tz)->format('d.m.Y H:i') }}
                        @if ($q->conversation) · <a href="{{ route('admin.chat-conversations.show', $q->conversation) }}" class="underline">{{ $q->customer?->displayName() ?? 'the chat' }}</a>@endif</p>
                </div>
                @if ($q->customer_text)<p class="text-slate-600"><span class="text-slate-400">Customer wrote:</span> {{ $q->customer_text }}</p>@endif
                <p class="text-slate-900"><span class="text-slate-400">Question:</span> {{ $q->question }}</p>
                @if ($q->ai_draft)<p class="text-slate-700"><span class="text-slate-400">AI's idea:</span> {{ $q->ai_draft }}</p>@endif
                @if ($q->note)<p class="text-slate-700"><span class="text-slate-400">Transfer note:</span> {{ $q->note }}</p>@endif
                @if ($q->status === 'asked')<p class="text-slate-600">With {{ $q->expert?->displayName() ?? '—' }} since {{ $q->asked_at?->setTimezone($tz)->format('d.m H:i') }}{{ $q->reminded_at ? ' · reminded' : '' }}</p>@endif
                @if (in_array($q->status, ['answered', 'unknown'], true))
                    <p class="text-slate-900"><span class="text-slate-400">{{ ExpertQuestion::VERDICTS[$q->verdict] ?? 'Answer' }} — {{ $q->answerer?->displayName() ?? '—' }}, {{ $q->answered_at?->setTimezone($tz)->format('d.m H:i') }}:</span> {{ $q->answer }}</p>
                    @if ($q->status === 'answered')<p class="text-xs text-slate-500">To the customer: {{ ['sent' => 'sent', 'shadow' => 'a shadow draft for a manager', 'next_turn' => 'in the next reply (the customer wrote meanwhile)', 'manager' => 'the managers were asked to send it'][$q->delivered] ?? 'not yet' }}</p>@endif
                @endif

                @if ($q->knowledge)
                    <details class="border-t border-slate-100 pt-2" @if((! $q->knowledge->is_active && $filter === 'review') || (string) old('question_id') === (string) $q->id) open @endif>
                        <summary class="cursor-pointer text-sm {{ $q->knowledge->is_active ? 'text-slate-500' : 'text-amber-800 font-medium' }}">
                            {{ $q->knowledge->is_active ? 'Knowledge approved' : 'Knowledge draft — review and approve' }}</summary>
                        <form method="POST" action="{{ route('admin.expert-questions.approve', $q) }}" class="space-y-2 mt-2">
                            @php $mine = (string) old('question_id') === (string) $q->id; @endphp
                            <input type="hidden" name="question_id" value="{{ $q->id }}">
                            @csrf @method('PUT')
                            <div class="grid gap-2 sm:grid-cols-3">
                                <select name="kind" class="{{ $input }}" aria-label="Section">
                                    @foreach (SalesKnowledge::KINDS as $kind => $k)
                                        <option value="{{ $kind }}" @selected(($mine ? old('kind') : $q->knowledge->kind) === $kind)>{{ $k[0] }}</option>
                                    @endforeach
                                </select>
                                <input name="title" maxlength="120" value="{{ $mine ? old('title') : $q->knowledge->title }}" class="{{ $input }} sm:col-span-2" aria-label="Title">
                            </div>
                            <textarea name="body" rows="4" maxlength="8000" class="{{ $input }}" aria-label="Knowledge text">{{ $mine ? old('body') : $q->knowledge->body }}</textarea>
                            <button class="text-sm rounded-lg bg-slate-900 text-white px-3 py-1.5 hover:bg-slate-700">{{ $q->knowledge->is_active ? 'Save' : 'Approve' }}</button>
                        </form>
                    </details>
                @endif

                @if (in_array($q->status, ExpertQuestion::OPEN, true))
                    <form method="POST" action="{{ route('admin.expert-questions.cancel', $q) }}" onsubmit="return confirm('Cancel this question?')">
                        @csrf
                        <button class="text-xs text-slate-500 underline">Cancel the question</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="text-sm text-slate-500">Nothing here.</p>
        @endforelse
    </div>
    {{ $questions->links() }}
</div>
@endsection
