@extends('admin.layout')
@section('title', 'Test chat')
@section('heading', 'AI Sales & Support Team — Test chat')

@section('content')
<div class="w-full space-y-6">
    @include('admin.ai-team._mock')

    <p class="text-sm text-slate-600 max-w-3xl">Play the customer and see exactly what the AI employee would answer — with a draft prompt, before real customers see it. The right panel shows what the AI decided behind the reply. Nothing is sent to Telegram; a good dialogue can be saved as a test case.</p>

    <div class="grid gap-4 lg:grid-cols-4">
        <section class="bg-white border border-slate-200 rounded-xl p-4 space-y-3 text-sm" aria-labelledby="setup-h">
            <h2 id="setup-h" class="font-semibold text-slate-900">Setup</h2>
            <label class="block"><span class="text-slate-600">AI employee</span>
                <select disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm">
                    @foreach ($employees as $e)<option>{{ $e['name'] }} — {{ $e['role'] }}</option>@endforeach
                </select></label>
            <label class="block"><span class="text-slate-600">Prompt version</span>
                <select disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"><option>v4 — draft</option><option>v3 — published</option></select></label>
            <label class="block"><span class="text-slate-600">Pretend the customer is</span>
                <select disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"><option>New, no portrait</option><option>Akmal · Oila Market (portrait)</option></select></label>
            <label class="block"><span class="text-slate-600">Chat</span>
                <select disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"><option>Bot</option><option>Premium account</option></select></label>
            <button type="button" disabled class="w-full rounded-lg border border-slate-300 px-3 py-1.5 opacity-50 cursor-not-allowed">Save as test case</button>
        </section>

        <section class="lg:col-span-2 bg-[#efeae2] border border-slate-200 rounded-xl p-4 flex flex-col gap-2 min-h-[24rem]" aria-label="Test conversation">
            <div class="self-start max-w-[80%] bg-white rounded-lg px-3 py-2 text-sm shadow-sm">Salom, dorixonaga skaner kerak</div>
            <div class="self-end max-w-[80%] bg-[#d9fdd3] rounded-lg px-3 py-2 text-sm shadow-sm">Assalomu alaykum! Dorixonangizda nechta kassa bor va dorilarni Asl Belgi bilan markirovka qilasizmi?</div>
            <div class="self-start max-w-[80%] bg-white rounded-lg px-3 py-2 text-sm shadow-sm">2 ta kassa, ha markirovka bor. Narxi qancha?</div>
            <div class="self-end max-w-[80%] bg-[#d9fdd3] rounded-lg px-3 py-2 text-sm shadow-sm">Markirovka uchun 2D skaner kerak bo‘ladi. Aniq narx va taklifni menejerimiz yuboradi — telefon raqamingizni qoldirasizmi?</div>
            <div class="mt-auto flex gap-2 pt-2">
                <input type="text" disabled placeholder="Write as the customer…" class="flex-1 rounded-full border-slate-300 bg-white text-sm">
                <button type="button" disabled class="rounded-full bg-slate-900 text-white px-4 text-sm opacity-50">Send</button>
            </div>
        </section>

        <section class="bg-white border border-slate-200 rounded-xl p-4 space-y-3 text-sm" aria-labelledby="why-h">
            <h2 id="why-h" class="font-semibold text-slate-900">Behind the reply</h2>
            <dl class="space-y-2">
                <div><dt class="text-xs text-slate-500">Stage</dt><dd>questions → contacts</dd></div>
                <div><dt class="text-xs text-slate-500">Understood</dt><dd>Pharmacy · 2 cash desks · marking · 2D scanner</dd></div>
                <div><dt class="text-xs text-slate-500">Rules applied</dt><dd>No price stated · one question per message</dd></div>
                <div><dt class="text-xs text-slate-500">Tool calls</dt><dd><code class="text-xs">search_knowledge("2D scanner marking")</code></dd></div>
                <div><dt class="text-xs text-slate-500">Cost</dt><dd>2 turns · 6 120 tokens · $0.04</dd></div>
            </dl>
        </section>
    </div>
</div>
@endsection
