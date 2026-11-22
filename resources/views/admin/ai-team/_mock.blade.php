{{-- Mock-up banner: these pages show sample data to agree the structure; nothing is saved or sent yet. --}}
<div class="flex items-start gap-3 rounded-xl border border-dashed border-slate-400 bg-slate-50 px-4 py-3 text-sm text-slate-700" role="note">
    <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>
    </svg>
    <p><span class="font-semibold">Mock-up.</span> Sample data to agree how the AI team and its training will work — nothing here is saved, sent or used by the bot yet. {{ $slot ?? '' }}</p>
</div>
