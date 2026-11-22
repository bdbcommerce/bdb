{{-- One conversation's messages (bot, Business, group, web), drawn as a messenger chat:
     the customer / group members on the left in white, our side (assistant, staff, account owner, bot) on the right in green.
     Needs $messages. The parent provides the chat background (bg-[#efeae2]). --}}
@php
    $tz = config('app.timezone');
    $lastDay = null;
    $prevSide = null;
    $prevLabel = null;
    $prevVia = null;
@endphp
<div class="flex flex-col">
@foreach($messages as $m)
    @php
        $role = $m['role'] ?? 'user';
        $mine = in_array($role, ['assistant', 'admin', 'owner', 'bot'], true);
        $at = null;
        try {
            $at = isset($m['at']) ? \Illuminate\Support\Carbon::parse($m['at'])->timezone($tz) : null;
        } catch (\Throwable) {
        }
        $day = $at?->toDateString();
        $newDay = $day && $day !== $lastDay;
        if ($day) {
            $lastDay = $day;
        }
        $first = $newDay || $prevSide !== $mine; // first of a run from one side: bubble tail + more space
        $prevSide = $mine;

        $isMedia = ! isset($m['content']) || ! empty($m['transcribed']);
        $isStep = ! $isMedia && $role === 'user' && ! empty($m['aside']);
        $unanswered = $role === 'assistant' && ! ($m['answered'] ?? true);

        $bubble = $mine ? 'bg-[#d9fdd3]' : 'bg-white';
        if ($isStep) {
            $bubble = 'bg-white/70 border border-dashed border-slate-400';
        } elseif ($unanswered) {
            $bubble .= ' ring-1 ring-amber-400';
        }
        $tail = $first ? ($mine ? 'rounded-tr-none' : 'rounded-tl-none') : '';

        $label = match (true) {
            $role === 'admin' => 'BDB team · '.($m['author'] ?? 'Admin'),
            $role === 'assistant' && filled($m['employee'] ?? null) => 'AI · '.$m['employee'].(filled($m['approved_by'] ?? null) ? ' (sent by '.$m['approved_by'].')' : ''),
            $role === 'owner' => 'Account owner'.(isset($m['sender']) ? ' · '.$m['sender'] : ''),
            isset($m['sender']) => $m['sender'],
            default => null,
        };
        $showLabel = $label && ($first || $label !== $prevLabel || $role === 'member');
        // Bot chat or Premium-account chat (one conversation per customer): shown where it changes.
        $via = $m['via'] ?? ($defaultVia ?? null);
        $showVia = $via && $via !== $prevVia && ($prevVia !== null || $via === 'assistant');
        $prevVia = $via ?? $prevVia;
        $prevLabel = $label;
    @endphp

    @if($newDay)
        <div class="flex justify-center my-3 first:mt-0">
            <span class="text-[11px] font-medium text-slate-600 bg-white/90 rounded-lg px-3 py-1 shadow-sm">
                {{ $at->isToday() ? 'Today' : ($at->isYesterday() ? 'Yesterday' : $at->format('d.m.Y')) }}
            </span>
        </div>
    @endif

    <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }} {{ $first ? 'mt-2.5 first:mt-0' : 'mt-0.5' }}">
        <div class="relative max-w-[85%] sm:max-w-[70%] {{ $bubble }} {{ $tail }} rounded-lg shadow-sm px-2.5 pt-1.5 pb-1 text-sm text-slate-900"
             @if($isMedia) x-data="{ shown: false }" @endif>
            @if($showLabel)
                <p class="text-xs font-semibold {{ $mine ? 'text-emerald-800' : 'text-slate-600' }} mb-0.5 break-words">{{ $label }}</p>
            @endif

            @if($isMedia)
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
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-0.5">
                    <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/>
                    </svg>
                    <span class="font-medium">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                    @if($url && $type === 'document')
                        <span class="text-xs text-slate-500">not downloaded (safety) — open it in Telegram</span>
                    @elseif($url)
                        @if($player)
                            <button type="button" x-show="! shown" @click="shown = true"
                                    class="text-xs font-medium text-slate-600 underline hover:text-slate-900">Load</button>
                        @endif
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="text-xs font-medium text-slate-600 underline hover:text-slate-900">Open<span class="sr-only"> {{ $type }} in a new tab</span></a>
                    @else
                        <span class="text-xs text-slate-500">not stored</span>
                    @endif
                </div>
                @if($url && $player)
                    <template x-if="shown">
                        <div class="mt-1">
                            @if($player === 'image')
                                <img src="{{ $url }}" alt="{{ ucfirst($type) }} sent by {{ $m['sender'] ?? 'the customer' }}" class="max-h-80 rounded-md">
                            @elseif($player === 'video')
                                <video src="{{ $url }}" controls class="max-h-80 rounded-md"></video>
                            @else
                                <audio src="{{ $url }}" controls class="w-64 max-w-full"></audio>
                            @endif
                        </div>
                    </template>
                @endif
                @isset($m['caption'])
                    <p class="mt-1 whitespace-pre-line break-words">{{ $m['caption'] }}</p>
                @endisset
                @if(! empty($m['transcribed']))
                    <p class="mt-1 whitespace-pre-line break-words"><span class="text-xs font-medium text-slate-500">Transcript:</span> {{ $m['content'] }}</p>
                    @if(! empty($m['aside']))<p class="text-[11px] text-slate-500">Not answered yet</p>@endif
                @endif
            @else
                <p class="whitespace-pre-line break-words">{{ $m['content'] }}</p>
            @endif

            {{-- Time and status, bottom right like a messenger --}}
            <p class="flex flex-wrap justify-end items-center gap-x-1.5 text-[11px] leading-4 {{ $mine ? 'text-slate-600' : 'text-slate-500' }} mt-0.5 -mb-0.5 text-right">
                @if($showVia)<span class="font-medium">{{ $via === 'assistant' ? 'via Premium account' : 'via bot' }}</span>@endif
                @if($isStep)<span>Bot step — not sent to the AI</span>@endif
                @if($m['deleted'] ?? false)<span class="font-medium text-red-700">deleted in Telegram</span>@endif
                @if($m['edited'] ?? false)<span>edited</span>@endif
                @if($at)<time datetime="{{ $at->toIso8601String() }}" title="{{ $at->format('d.m.Y H:i') }}">{{ $at->format('H:i') }}</time>@endif
            </p>

            @if($unanswered)
                <p class="text-xs text-amber-800 mt-1 border-t border-amber-200 pt-1">
                    No answer in the site data.
                    <a href="{{ route('admin.knowledge.create') }}" class="font-medium underline">Add a knowledge entry</a>
                </p>
            @endif
        </div>
    </div>
@endforeach
</div>
