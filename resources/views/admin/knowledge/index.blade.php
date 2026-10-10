@extends('admin.layout')
@section('title', 'AI Knowledge')
@section('heading', 'AI Knowledge')
@section('header-actions')
    <a href="{{ route('admin.knowledge.create') }}"
       class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">
        Add Entry
    </a>
@endsection

@section('content')
<div class="bg-white border border-slate-200 rounded-xl p-4 mb-4 text-sm text-slate-600 flex gap-3">
    <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
    </svg>
    <p>The website chat assistant answers <strong>only</strong> from these entries plus Company Info, Brands, Industries, Customers and published News. If a question isn't covered, it offers a callback instead of guessing.</p>
</div>

<div class="flex flex-wrap gap-2 mb-3">
    <a href="{{ route('admin.knowledge.index') }}"
       class="text-xs font-medium px-3 py-1.5 rounded-full border {{ !$type ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 text-slate-600 hover:bg-slate-100' }}">All</a>
    @foreach(\App\Models\KnowledgeEntry::TYPES as $key => $label)
        <a href="{{ route('admin.knowledge.index', ['type' => $key]) }}"
           class="text-xs font-medium px-3 py-1.5 rounded-full border {{ $type === $key ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($entries->isEmpty())
        <div class="px-6 py-16 text-center">
            <svg class="w-10 h-10 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/>
            </svg>
            <p class="text-sm font-medium text-slate-500">No knowledge entries yet</p>
            <p class="text-xs text-slate-400 mt-1">Add the questions customers ask most: warranty, delivery, Asl Belgi, how to order.</p>
        </div>
    @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Type</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Question</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Status</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Order</th>
                    <th class="px-4 py-3 w-20"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($entries as $entry)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex text-xs font-medium px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ \App\Models\KnowledgeEntry::TYPES[$entry->type] ?? $entry->type }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-slate-900">{{ $entry->getTranslation('question', 'ru') }}</p>
                        <p class="text-xs text-slate-500 mt-0.5">{{ Str::limit($entry->getTranslation('answer', 'ru'), 120) }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex text-xs font-medium px-2 py-0.5 rounded-full {{ $entry->is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500' }}">
                            {{ $entry->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $entry->sort_order }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('admin.knowledge.edit', $entry) }}" title="Edit"
                               class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                                </svg>
                            </a>
                            <form method="POST" action="{{ route('admin.knowledge.destroy', $entry) }}" class="inline"
                                  onsubmit="return confirm('Delete this entry?')">
                                @csrf @method('DELETE')
                                <button type="submit" title="Delete"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>
@endsection
