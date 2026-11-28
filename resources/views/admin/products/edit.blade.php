@extends('admin.layout')
@section('title', $product->name)
@section('heading', 'Product — '.$product->name)

@section('content')
@php
    $input = 'mt-1 w-full rounded-lg border-slate-300 text-sm';
    $empty = $product->emptyAiFields();
    // old() can be an array after a crafted request: show the saved value then.
    $val = fn (string $field) => is_string($o = old($field, $product->$field)) ? $o : $product->$field;
@endphp
<div class="w-full max-w-3xl space-y-6">
    <a href="{{ route('admin.products.index') }}" class="text-sm text-slate-600 underline">Back to products</a>

    <section class="bg-white border border-slate-200 rounded-xl p-5" aria-labelledby="from-1c">
        <h2 id="from-1c" class="text-sm font-semibold text-slate-900">From 1C <span class="font-normal text-slate-400">— read only, replaced by every load</span></h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 text-sm">
            @foreach(['1C code' => $product->code, 'Name' => $product->name, 'Short name' => $product->short_name, 'Full name' => $product->full_name, 'Unit' => $product->unit,
                      'Barcode' => $product->barcode, 'MXIK' => $product->mxik, '1C category number' => $product->category_id] as $label => $value)
                <div><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="text-slate-900 break-words">{{ filled($value) ? $value : '—' }}</dd></div>
            @endforeach
            <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Description</dt><dd class="text-slate-900 break-words whitespace-pre-line">{{ $product->description ?: '—' }}</dd></div>
        </dl>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-4" aria-labelledby="for-ai">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="for-ai" class="text-sm font-semibold text-slate-900">Information for the AI team</h2>
                <p class="text-xs text-slate-500 mt-1">
                    @if($product->info_status === 'checked')
                        Checked by {{ $product->info_by }} {{ $product->info_at?->diffForHumans() }}.
                    @elseif($product->info_status === 'ai')
                        Drafted by the AI ({{ $product->info_by }}) {{ $product->info_at?->diffForHumans() }} — read it, correct it and press Save to mark it checked.
                    @else
                        Nothing filled in yet. Type it, or let the AI draft it from the 1C texts above.
                    @endif
                </p>
            </div>
            @if($empty && $product->ai_use)
                <form method="POST" action="{{ route('admin.products.ai-fill', $product) }}" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'The AI is reading…';">@csrf
                    <button class="border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 disabled:opacity-60" title="Fills only the empty fields, from the 1C texts. What you typed is never replaced.">Fill empty fields with AI</button>
                    <p class="text-xs text-slate-400 mt-1 max-w-56">Save first: typing that is not saved is lost.</p>
                </form>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.products.update', $product) }}" class="space-y-4">
            @csrf @method('PUT')
            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block text-sm"><span class="text-slate-600">Brand</span>
                    <input name="brand" type="text" maxlength="80" value="{{ $val('brand') }}" placeholder="Xprinter" class="{{ $input }}"></label>
                <label class="block text-sm"><span class="text-slate-600">Model</span>
                    <input name="model" type="text" maxlength="120" value="{{ $val('model') }}" placeholder="XP-Q807K" class="{{ $input }}"></label>
                <label class="block text-sm"><span class="text-slate-600">Category</span>
                    <select name="ai_category" class="{{ $input }}">
                        <option value="">Not chosen</option>
                        @foreach(\App\Models\OnecItem::categories() as $key => $label)<option value="{{ $key }}" @selected($val('ai_category') === $key)>{{ $label }}</option>@endforeach
                    </select></label>
            </div>
            <label class="block text-sm"><span class="text-slate-600">What it is and who needs it</span>
                <textarea name="ai_summary" rows="3" maxlength="1000" class="{{ $input }}">{{ $val('ai_summary') }}</textarea></label>
            <label class="block text-sm"><span class="text-slate-600">Specifications — one per line, “name: value”</span>
                <textarea name="specs" rows="5" maxlength="2000" placeholder="Ширина печати: 80 мм&#10;Интерфейсы: USB, LAN" class="{{ $input }} font-mono">{{ $val('specs') }}</textarea></label>
            <label class="block text-sm"><span class="text-slate-600">Works with (devices, consumables)</span>
                <textarea name="compatible_with" rows="2" maxlength="1000" class="{{ $input }}">{{ $val('compatible_with') }}</textarea></label>
            <label class="block text-sm"><span class="text-slate-600">Words customers use (uz / ru / en, comma-separated)</span>
                <textarea name="keywords" rows="2" maxlength="500" class="{{ $input }}">{{ $val('keywords') }}</textarea></label>
            <label class="block text-sm"><span class="text-slate-600">Sales note — staff only, the AI never writes here</span>
                <textarea name="sales_note" rows="2" maxlength="1000" placeholder="What to offer with it, what to warn the customer about" class="{{ $input }}">{{ $val('sales_note') }}</textarea></label>
            <label class="flex items-start gap-2 text-sm">
                <input type="hidden" name="ai_use" value="0">
                <input type="checkbox" name="ai_use" value="1" @checked(old('ai_use', $product->ai_use)) class="mt-0.5 rounded border-slate-300">
                <span><span class="text-slate-700">The AI may offer this product</span>
                    <span class="block text-xs text-slate-400">Untick for items made for one customer, internal items and anything not for sale. Hidden products are not sent to the AI either.</span></span>
            </label>
            <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Save and mark as checked</button>
        </form>
    </section>
</div>
@endsection
