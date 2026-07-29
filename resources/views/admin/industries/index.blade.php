@extends('admin.layout')
@section('title', 'Industries')
@section('heading', 'Industries')
@section('header-actions')
    <a href="{{ route('admin.industries.create') }}"
       class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">
        Add Industry
    </a>
@endsection

@section('content')
<p class="text-xs text-slate-500 mb-3">{{ $industries->count() }} {{ Str::plural('industry', $industries->count()) }}</p>
<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($industries->isEmpty())
        <div class="px-6 py-16 text-center">
            <svg class="w-10 h-10 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"/>
            </svg>
            <p class="text-sm font-medium text-slate-500">No industries yet</p>
            <p class="text-xs text-slate-400 mt-1">Click "Add Industry" above to create the first one.</p>
        </div>
    @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Name</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Icon</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Status</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Order</th>
                    <th class="px-4 py-3 w-20"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($industries as $industry)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-900">{{ $industry->getTranslation('name', 'en') }}</td>
                    <td class="px-4 py-3 text-slate-500 font-mono text-xs">{{ $industry->icon ?: '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex text-xs font-medium px-2 py-0.5 rounded-full {{ $industry->is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500' }}">
                            {{ $industry->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $industry->sort_order }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('admin.industries.edit', $industry) }}" title="Edit"
                               class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                                </svg>
                            </a>
                            <form method="POST" action="{{ route('admin.industries.destroy', $industry) }}" class="inline"
                                  onsubmit="return confirm('Delete this industry?')">
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
