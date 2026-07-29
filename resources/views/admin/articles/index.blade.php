@extends('admin.layout')
@section('title', 'Articles')
@section('heading', 'Articles')
@section('header-actions')
    <a href="{{ route('admin.articles.create') }}"
       class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">
        New Article
    </a>
@endsection

@section('content')
<p class="text-xs text-slate-500 mb-3">{{ $articles->total() }} {{ Str::plural('article', $articles->total()) }}</p>
<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($articles->isEmpty())
        <div class="px-6 py-16 text-center">
            <svg class="w-10 h-10 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
            </svg>
            <p class="text-sm font-medium text-slate-500">No articles yet</p>
            <p class="text-xs text-slate-400 mt-1">Click "New Article" above to write the first one.</p>
        </div>
    @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-slate-600 w-16">Image</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Title</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Category</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Status</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-600">Published</th>
                    <th class="px-4 py-3 w-20"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($articles as $article)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3">
                        @if($article->featured_image)
                            <img src="{{ Storage::url($article->featured_image) }}" alt=""
                                 class="h-9 w-14 object-cover rounded-lg border border-slate-100 grayscale">
                        @else
                            <div class="h-9 w-14 rounded-lg bg-slate-100 flex items-center justify-center">
                                <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
                                </svg>
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-medium text-slate-900 max-w-xs truncate">{{ $article->getTranslation('title', 'en') }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $article->category?->getTranslation('name', 'en') }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex text-xs font-medium px-2 py-0.5 rounded-full {{ $article->is_published ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' }}">
                            {{ $article->is_published ? 'Published' : 'Draft' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-500 text-xs">
                        {{ $article->published_at?->format('d M Y') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('admin.articles.edit', $article) }}" title="Edit"
                               class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                                </svg>
                            </a>
                            <form method="POST" action="{{ route('admin.articles.destroy', $article) }}" class="inline"
                                  onsubmit="return confirm('Delete this article?')">
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
        <div class="px-4 py-3 border-t border-slate-100">
            {{ $articles->links() }}
        </div>
    @endif
</div>
@endsection
