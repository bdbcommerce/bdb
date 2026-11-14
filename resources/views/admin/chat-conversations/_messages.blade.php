{{-- One conversation's messages (bot, Business, group, web). Needs $messages. --}}
@foreach($messages as $m)
    @php $right = $m['role'] === 'user'; @endphp
    @isset($m['sender'])
        {{-- Recorded chats (Telegram Business, groups): who wrote it and when --}}
        <p class="text-[11px] text-slate-500 -mb-2 {{ $right ? 'text-right' : '' }}">
            <span class="font-semibold">{{ $m['role'] === 'owner' ? 'Account owner · ' : '' }}{{ $m['sender'] }}</span>
            @isset($m['at']) · {{ \Illuminate\Support\Carbon::parse($m['at'])->timezone(config('app.timezone'))->format('d.m H:i') }} @endisset
            @if($m['edited'] ?? false) · edited @endif
            @if($m['deleted'] ?? false) · <span class="font-medium text-red-700">deleted in Telegram</span> @endif
        </p>
    @endisset
    @if(! isset($m['content']) || ! empty($m['transcribed']))
        {{-- Telegram media: only type + file ID are stored; the file is fetched from Telegram on demand --}}
        @php
            $type = $m['type'] ?? 'other';
            $url = ! empty($m['file_id']) ? route('admin.telegram.media', $m['file_id']) : null;
            $player = match ($type) {
                'photo', 'sticker' => 'image',
                'video', 'animation', 'video_note' => 'video',
                'voice', 'audio' => 'audio',
                default => null,
            };
        @endphp
        <div class="flex {{ $right ? 'justify-end' : '' }}" x-data="{ shown: false }">
            <div class="max-w-[85%] border border-slate-300 bg-white text-sm rounded-2xl {{ $right ? 'rounded-tr-sm' : 'rounded-tl-sm' }} px-3.5 py-2.5 space-y-2">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/>
                    </svg>
                    <span class="font-medium text-slate-800">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                    @if($url)
                        @if($player)
                            <button type="button" x-show="! shown" @click="shown = true"
                                    class="text-xs font-medium text-slate-600 underline hover:text-slate-900">Load</button>
                        @endif
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="text-xs font-medium text-slate-600 underline hover:text-slate-900">Open<span class="sr-only"> {{ $type }} in a new tab</span></a>
                    @else
                        <span class="text-xs text-slate-400">not stored</span>
                    @endif
                </div>
                @isset($m['caption'])
                    <p class="text-slate-800 whitespace-pre-line break-words">{{ $m['caption'] }}</p>
                @endisset
                @if(! empty($m['transcribed']))
                    <p class="text-slate-800 whitespace-pre-line break-words"><span class="text-xs font-medium text-slate-500">Transcript:</span> {{ $m['content'] }}</p>
                    @if(! empty($m['aside']))<p class="text-[11px] text-slate-400">Not answered yet</p>@endif
                @endif
                @if($url && $player)
                    <template x-if="shown">
                        <div>
                            @if($player === 'image')
                                <img src="{{ $url }}" alt="{{ ucfirst($type) }} sent by {{ $m['sender'] ?? 'the customer' }}" class="max-h-80 rounded-lg border border-slate-200">
                            @elseif($player === 'video')
                                <video src="{{ $url }}" controls class="max-h-80 rounded-lg border border-slate-200"></video>
                            @else
                                <audio src="{{ $url }}" controls class="w-64 max-w-full"></audio>
                            @endif
                        </div>
                    </template>
                @endif
            </div>
        </div>
    @elseif($m['role'] === 'user' && ! empty($m['aside']))
        {{-- Registration step, command or button tap: recorded, not a question for the AI --}}
        <div class="flex flex-col items-end">
            <p class="max-w-[85%] border border-dashed border-slate-400 bg-white text-slate-700 text-sm rounded-2xl rounded-tr-sm px-3.5 py-2.5 whitespace-pre-line break-words">{{ $m['content'] }}</p>
            <p class="text-[11px] text-slate-400 mt-1">Bot step — not sent to the AI</p>
        </div>
    @elseif($m['role'] === 'user')
        <div class="flex justify-end">
            <p class="max-w-[85%] bg-slate-900 text-white text-sm rounded-2xl rounded-tr-sm px-3.5 py-2.5 whitespace-pre-line break-words">{{ $m['content'] }}</p>
        </div>
    @elseif($m['role'] === 'owner')
        <p class="max-w-[85%] bg-white border-2 border-slate-900 text-slate-900 text-sm rounded-2xl rounded-tl-sm px-3.5 py-2.5 whitespace-pre-line break-words">{{ $m['content'] }}</p>
    @elseif($m['role'] === 'member')
        <p class="max-w-[85%] bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-2xl rounded-tl-sm px-3.5 py-2.5 whitespace-pre-line break-words">{{ $m['content'] }}</p>
    @elseif($m['role'] === 'admin')
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">BDB team · {{ $m['author'] ?? 'Admin' }}</p>
            <p class="max-w-[85%] bg-white border-2 border-slate-900 text-slate-900 text-sm rounded-2xl rounded-tl-sm px-3.5 py-2.5 whitespace-pre-line break-words">{{ $m['content'] }}</p>
        </div>
    @else
        <div>
            <p class="max-w-[85%] bg-slate-50 border text-sm rounded-2xl rounded-tl-sm px-3.5 py-2.5 whitespace-pre-line break-words {{ ($m['answered'] ?? true) ? 'border-slate-200 text-slate-800' : 'border-amber-300 text-slate-800' }}">{{ $m['content'] }}</p>
            @unless($m['answered'] ?? true)
                <p class="text-xs text-amber-700 mt-1">
                    No answer in the site data.
                    <a href="{{ route('admin.knowledge.create') }}" class="font-medium underline">Add a knowledge entry</a>
                </p>
            @endunless
        </div>
    @endif
@endforeach
