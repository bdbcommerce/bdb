{{-- One waiting shadow reply with Send / Discard. Needs $draft (AiShadowDraft). --}}
<div class="rounded-lg border border-dashed border-slate-400 bg-white p-3 space-y-2">
    <p class="text-xs text-slate-600">
        <span class="font-semibold text-slate-900">{{ $draft->employee?->name ?? 'AI employee' }}</span>
        {{ $draft->employee ? '· '.mb_strtolower($draft->employee->role) : '' }} · shadow reply{{ $draft->prompt_version ? ' (prompt v'.$draft->prompt_version.')' : '' }}
        · {{ $draft->created_at->timezone(config('app.timezone'))->format('d.m H:i') }} — not sent yet
    </p>
    <form method="POST" action="{{ route('admin.ai-team.shadow.send', $draft) }}" class="space-y-2">
        @csrf
        <label for="draft-{{ $draft->id }}" class="sr-only">Reply to send</label>
        <textarea id="draft-{{ $draft->id }}" name="text" rows="3" maxlength="3500" required class="w-full rounded-lg border-slate-300 text-sm">{{ old('text', $draft->draft) }}</textarea>
        <div class="flex flex-wrap items-center gap-2">
            <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-3 py-1.5 hover:bg-slate-700">Send to the customer</button>
            <span class="text-xs text-slate-500">Edit first if needed — every change is kept to train {{ $draft->employee?->name ?? 'the AI' }}.</span>
        </div>
    </form>
    <form method="POST" action="{{ route('admin.ai-team.shadow.discard', $draft) }}">
        @csrf
        <button class="text-xs text-slate-500 underline hover:text-slate-900">Discard (I will answer myself)</button>
    </form>
</div>
