@extends('admin.layout')
@section('title', $case->exists ? 'Edit test case' : 'New test case')
@section('heading', $case->exists ? 'Test case — '.$case->name : 'New test case')

@section('content')
<div class="w-full max-w-3xl space-y-6">
    <a href="{{ route('admin.ai-test-cases.index') }}" class="text-sm text-slate-600 underline">All test cases</a>

    <form method="POST" action="{{ $case->exists ? route('admin.ai-test-cases.update', $case) : route('admin.ai-test-cases.store') }}" class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
        @csrf
        @if ($case->exists) @method('PUT') @endif
        <label class="block text-sm"><span class="text-slate-600">Name</span>
            <input name="name" type="text" maxlength="150" required value="{{ old('name', $case->name) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm" placeholder="Pharmacy needs a marking scanner"></label>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm"><span class="text-slate-600">Tested on</span>
                <select name="ai_employee_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">Every AI employee</option>
                    @foreach ($employees as $e)
                        <option value="{{ $e->id }}" @selected((string) old('ai_employee_id', $case->ai_employee_id) === (string) $e->id)>{{ $e->name }} — {{ $e->role }}</option>
                    @endforeach
                </select></label>
            <label class="block text-sm"><span class="text-slate-600">Customer writes to</span>
                <select name="via" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="bot" @selected(old('via', $case->via) !== 'assistant')>The bot</option>
                    <option value="assistant" @selected(old('via', $case->via) === 'assistant')>The Premium account</option>
                </select></label>
        </div>
        <label class="block text-sm"><span class="text-slate-600">What the customer says — one message per line, sent one by one</span>
            <textarea name="turns_text" rows="5" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('turns_text', implode("\n", $case->turns ?? [])) }}</textarea></label>
        @error('turns')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <label class="block text-sm"><span class="text-slate-600">Checklist — one item per line, each graded pass / fail</span>
            <textarea name="criteria_text" rows="5" class="mt-1 w-full rounded-lg border-slate-300 text-sm" placeholder="Never states a price&#10;Asks about the business before suggesting a model">{{ old('criteria_text', implode("\n", $case->criteria ?? [])) }}</textarea></label>
        @error('criteria')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <label class="inline-flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $case->is_active)) class="rounded border-slate-300"> Active (part of the exam)</label>
        <div><button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">{{ $case->exists ? 'Save' : 'Add test case' }}</button></div>
    </form>

    @if ($case->exists)
        <form method="POST" action="{{ route('admin.ai-test-cases.destroy', $case) }}" onsubmit="return confirm('Delete this test case and its runs?')">
            @csrf @method('DELETE')
            <button class="text-sm text-slate-500 underline hover:text-red-700">Delete this test case</button>
        </form>
    @endif
</div>
@endsection
