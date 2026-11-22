@extends('admin.layout')
@section('title', $client->exists ? 'Edit '.$client->name : 'New company')
@section('heading', $client->exists ? 'Company — '.$client->name : 'New company')

@section('content')
<div class="w-full max-w-3xl space-y-6">
    <a href="{{ $client->exists ? route('admin.clients.show', $client) : route('admin.clients.index') }}" class="text-sm text-slate-600 underline">Back</a>

    <form method="POST" action="{{ $client->exists ? route('admin.clients.update', $client) : route('admin.clients.store') }}" class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
        @csrf
        @if ($client->exists) @method('PUT') @endif
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (['name' => ['Company name', 150, true], 'inn' => ['INN', 20, false], 'industry' => ['Industry (shop, pharmacy, warehouse…)', 100, false], 'city' => ['City', 80, false],
                       'size' => ['Size (branches, cash desks)', 150, false], 'since' => ['Client since', 20, false], 'owner' => ['Looks after them (manager)', 100, false]] as $field => [$label, $max, $required])
                <label class="block text-sm"><span class="text-slate-600">{{ $label }}</span>
                    <input name="{{ $field }}" type="text" maxlength="{{ $max }}" @if($required) required @endif value="{{ old($field, $client->$field) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            @endforeach
            <label class="block text-sm"><span class="text-slate-600">Status</span>
                <select name="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    @foreach (\App\Models\Client::STATUSES as $k => $label)<option value="{{ $k }}" @selected(old('status', $client->status) === $k)>{{ $label }}</option>@endforeach
                </select></label>
        </div>
        <label class="block text-sm"><span class="text-slate-600">Installed equipment — one item per line (model, quantity, year, warranty)</span>
            <textarea name="installed" rows="4" maxlength="3000" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('installed', $client->installed) }}</textarea></label>
        <label class="block text-sm"><span class="text-slate-600">Consumables (what and how often)</span>
            <textarea name="consumables" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('consumables', $client->consumables) }}</textarea></label>
        <label class="block text-sm"><span class="text-slate-600">Notes for the AI team (plans, how they decide, what to avoid)</span>
            <textarea name="notes" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('notes', $client->notes) }}</textarea></label>
        <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">{{ $client->exists ? 'Save' : 'Add company' }}</button>
    </form>

    @if ($client->exists)
        <form method="POST" action="{{ route('admin.clients.destroy', $client) }}" onsubmit="return confirm('Delete this company? Its people are kept without a company.')">
            @csrf @method('DELETE')
            <button class="text-sm text-slate-500 underline hover:text-red-700">Delete this company</button>
        </form>
    @endif
</div>
@endsection
