{{--
    A Telegram chat / group ID field with a "choose from the list" dropdown (TelegramChat::options).
    kind: groups (groups the bot is in) | chats (private chats with the bot) | people (Telegram contacts).
    separator: null = one ID (the choice replaces it); ", " or "newline" = a list (the choice is added).
    The field stays editable: an ID can still be typed by hand.
--}}
@props(['name', 'value' => null, 'kind' => 'groups', 'separator' => null, 'rows' => null, 'placeholder' => null, 'class' => ''])
@php
    $options = \App\Models\TelegramChat::options($kind);
    $separator = $separator === 'newline' ? "\n" : $separator;
    $id = $attributes->get('id', $name);
    $empty = ['groups' => 'No groups yet — add the bot to the group and post a message there.', 'chats' => 'Nobody yet — the person has to press Start in the bot.', 'people' => 'No Telegram contacts yet.'][$kind] ?? '';
@endphp
<div x-data="{
        v: @js((string) $value),
        names: @js($options->all()),
        sep: @js($separator),
        pick(e) {
            const id = e.target.value; e.target.value = '';
            if (! id) return;
            if (this.sep === null) { this.v = id; return; }
            const list = this.v.split(/[\s,]+/).filter(Boolean);
            if (! list.includes(id)) this.v = [...list, id].join(this.sep);
        },
        get chosen() {
            return this.v.split(/[\s,]+/).filter(Boolean).map(i => this.names[i] ? this.names[i] + ' (' + i + ')' : null).filter(Boolean).join(', ');
        },
    }">
    <div class="flex flex-col sm:flex-row gap-2">
        @if ($rows)
            <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" x-model="v" placeholder="{{ $placeholder }}" {{ $attributes->except(['id', 'class']) }} class="{{ $class }} font-mono">{{ $value }}</textarea>
        @else
            <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" x-model="v" placeholder="{{ $placeholder }}" {{ $attributes->except(['id', 'class']) }} class="{{ $class }} font-mono">
        @endif
        @if ($options->isNotEmpty())
            <label for="{{ $id }}-pick" class="sr-only">Choose from the list</label>
            <select id="{{ $id }}-pick" @change="pick($event)" class="sm:w-56 shrink-0 self-start border border-slate-300 rounded-lg px-2 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-400">
                <option value="">{{ $separator === null ? 'Choose…' : 'Add…' }}</option>
                @foreach ($options as $optionId => $label)
                    <option value="{{ $optionId }}">{{ \Illuminate\Support\Str::limit($label, 60) }} · {{ $optionId }}</option>
                @endforeach
            </select>
        @endif
    </div>
    <p class="text-xs text-slate-500 mt-1" x-show="chosen" x-text="chosen" x-cloak></p>
    @if ($options->isEmpty())
        <p class="text-xs text-slate-400 mt-1">{{ $empty }}</p>
    @endif
</div>
