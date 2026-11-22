@extends('admin.layout')
@php use App\Models\ClientPerson; $p = $person; @endphp
@section('title', $p->exists ? $p->name : 'New person')
@section('heading', $p->exists ? 'Person — '.$p->name : 'New person')

@section('content')
<div class="w-full max-w-3xl space-y-6">
    <a href="{{ $p->client_id ? route('admin.clients.show', $p->client_id) : route('admin.clients.index') }}" class="text-sm text-slate-600 underline">Back</a>

    @if ($suggested)
        <p class="rounded-lg border border-dashed border-slate-400 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            Filled in from the Telegram customer and what the AI collected in the chat — check before saving.
        </p>
    @endif

    <form method="POST" action="{{ $p->exists ? route('admin.client-people.update', $p) : route('admin.client-people.store') }}" class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
        @csrf
        @if ($p->exists) @method('PUT') @endif
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm"><span class="text-slate-600">Name</span>
                <input name="name" type="text" maxlength="100" required value="{{ old('name', $p->name) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">Company</span>
                <select name="client_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">— none —</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}" @selected((string) old('client_id', $p->client_id) === (string) $c->id)>{{ $c->name }}</option>@endforeach
                </select></label>
            @unless ($p->exists)
                <label class="block text-sm sm:col-span-2"><span class="text-slate-600">…or a new company (created with this person)</span>
                    <input name="new_company" type="text" maxlength="150" value="{{ old('new_company', ! $p->client_id ? ($suggested['company'] ?? '') : '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <input type="hidden" name="new_city" value="{{ $suggested['city'] ?? '' }}"><input type="hidden" name="new_industry" value="{{ $suggested['industry'] ?? '' }}"></label>
            @endunless
            <label class="block text-sm"><span class="text-slate-600">Position</span>
                <input name="position" type="text" maxlength="100" value="{{ old('position', $p->position) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">Department</span>
                <input name="department" type="text" maxlength="100" value="{{ old('department', $p->department) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">Part in decisions</span>
                <select name="part" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">—</option>
                    @foreach (ClientPerson::PARTS as $k => $label)<option value="{{ $k }}" @selected(old('part', $p->part) === $k)>{{ $label }}</option>@endforeach
                </select></label>
            <label class="block text-sm"><span class="text-slate-600">Status</span>
                <select name="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    @foreach (ClientPerson::STATUSES as $k => $label)<option value="{{ $k }}" @selected(old('status', $p->status) === $k)>{{ $label }}</option>@endforeach
                </select></label>
            <label class="block text-sm"><span class="text-slate-600">Telegram ID (links their chats)</span>
                <input name="telegram_id" type="text" inputmode="numeric" maxlength="20" value="{{ old('telegram_id', $p->telegramUser?->telegram_id) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">Phone</span>
                <input name="phone" type="text" maxlength="30" value="{{ old('phone', $p->phone) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        </div>
        @error('telegram_user_id')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <label class="block text-sm"><span class="text-slate-600">How to talk to them (language, tone, what they like)</span>
            <input name="style" type="text" maxlength="300" value="{{ old('style', $p->style) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm" placeholder="Short and direct, Russian, wants documents"></label>
        <label class="block text-sm"><span class="text-slate-600">Notes</span>
            <textarea name="notes" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('notes', $p->notes) }}</textarea></label>
        <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">{{ $p->exists ? 'Save' : 'Add person' }}</button>
    </form>

    @if ($p->exists && ! in_array($p->status, ['left', 'fired'], true) && ! $p->next && $clients->where('id', '!=', $p->client_id)->isNotEmpty())
        <form method="POST" action="{{ route('admin.client-people.move', $p) }}" class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
            @csrf
            <h2 class="text-sm font-semibold text-slate-900">Moved to another company</h2>
            <p class="text-xs text-slate-500">This card becomes “Left the company”; a new card starts at the new company with the same Telegram link and style.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm"><span class="text-slate-600">New company</span>
                    <select name="client_id" required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        @foreach ($clients->where('id', '!=', $p->client_id) as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select></label>
                <label class="block text-sm"><span class="text-slate-600">Position there</span>
                    <input name="position" type="text" maxlength="100" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            </div>
            <button class="text-sm rounded-lg border border-slate-300 px-4 py-2 hover:border-slate-500">Move</button>
        </form>
    @endif
    @if ($p->exists)
        <form method="POST" action="{{ route('admin.client-people.destroy', $p) }}" onsubmit="return confirm('Delete this person?')">
            @csrf @method('DELETE')
            <button class="text-sm text-slate-500 underline hover:text-red-700">Delete this person</button>
        </form>
    @endif
</div>
@endsection
