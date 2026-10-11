@extends('admin.layout')
@section('title', 'AI settings')
@section('heading', 'AI settings')

@section('header-actions')
    <a href="{{ route('admin.ai-models.create') }}"
       class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">Add model</a>
@endsection

@section('content')
<div class="w-full space-y-6">
    @if (session('ai_error'))
        <div role="alert" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800 break-words whitespace-pre-line">{{ session('ai_error') }}</div>
    @endif

    <p class="text-xs text-slate-500">
        The assistant tries the models for each job from the top (lowest priority number first). When one fails
        (quota used up, wrong key, timeout) it is paused for a while and the next one answers, so customers keep getting replies.
        Add a few free models as backups. API keys are stored encrypted and never shown again.
    </p>

    {{-- Configured models --}}
    <section class="bg-white border border-slate-200 rounded-xl overflow-hidden" aria-labelledby="models-heading">
        <h2 id="models-heading" class="px-5 pt-5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Models in use</h2>
        @if($models->isEmpty())
            <div class="px-5 py-6 text-sm text-slate-600">
                No models added yet. The assistant uses Gemini from <code class="bg-slate-100 px-1 rounded">.env</code>
                ({{ $env['model'] }}, key {{ $env['key'] ? 'set' : 'not set' }}).
                @unless($env['key'])
                    <span class="text-red-700">Without a key the AI cannot answer: add a model below.</span>
                @endunless
            </div>
        @else
            <ul class="divide-y divide-slate-100 mt-3" role="list">
                @foreach($models as $model)
                    <li class="px-5 py-4 flex flex-wrap items-start gap-x-4 gap-y-2">
                        <span class="shrink-0 w-9 h-9 rounded-lg bg-slate-100 text-slate-700 text-xs font-semibold flex items-center justify-center" title="Priority">{{ $model->priority }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-900">
                                <a href="{{ route('admin.ai-models.edit', $model) }}" class="hover:underline">{{ $model->name }}</a>
                                <span class="text-xs font-normal text-slate-400 break-all">· {{ $model->model }}</span>
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ collect($model->tasks)->map(fn ($t) => $tasks[$t] ?? $t)->map(fn ($t) => Str::before($t, ' ('))->implode(', ') }}
                                · {{ $model->provider === 'gemini' ? 'Gemini' : parse_url((string) $model->base_url, PHP_URL_HOST) }}
                                · key {{ filled($model->api_key) ? 'saved' : ($model->provider === 'gemini' && $env['key'] ? 'from .env' : 'missing') }}
                            </p>
                            <p class="text-xs mt-1">
                                @if(! $model->is_active && blank($model->api_key))
                                    <span class="text-slate-500">Off, no key yet · <a href="{{ route('admin.ai-models.edit', $model) }}" class="underline hover:text-slate-900">how to get the key</a></span>
                                @elseif(! $model->is_active)
                                    <span class="text-slate-400">Off</span>
                                @elseif($model->isPaused())
                                    <span class="text-amber-700">Paused until {{ $model->cooldown_until->format('H:i') }}</span>
                                @elseif($model->last_success_at && (! $model->last_error_at || $model->last_success_at->gt($model->last_error_at)))
                                    <span class="text-slate-700">Working · last answer {{ $model->last_success_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-slate-400">Not used yet</span>
                                @endif
                                @if($model->last_error_at)
                                    <span class="block text-slate-400 break-words">Last error {{ $model->last_error_at->diffForHumans() }}: {{ Str::limit($model->last_error, 180) }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <form method="POST" action="{{ route('admin.ai-models.test', $model) }}">
                                @csrf
                                <button class="text-xs font-medium border border-slate-300 rounded-lg px-3 py-1.5 text-slate-700 hover:bg-slate-50">Test</button>
                            </form>
                            @if($model->isPaused())
                                <form method="POST" action="{{ route('admin.ai-models.resume', $model) }}">
                                    @csrf
                                    <button class="text-xs font-medium border border-slate-300 rounded-lg px-3 py-1.5 text-slate-700 hover:bg-slate-50">Use again</button>
                                </form>
                            @endif
                            <a href="{{ route('admin.ai-models.edit', $model) }}" class="text-xs text-slate-500 underline hover:text-slate-900">Edit</a>
                            <form method="POST" action="{{ route('admin.ai-models.destroy', $model) }}" onsubmit="return confirm('Remove this model?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-slate-400 hover:text-red-600">Remove</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Free presets --}}
    @if($presets)
    <section class="bg-white border border-slate-200 rounded-xl p-5" aria-labelledby="presets-heading">
        <h2 id="presets-heading" class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Free models to add</h2>
        <p class="text-xs text-slate-400 mt-1">Each needs a free API key from the provider. Free limits and model names change over time, so check the provider's page if a test fails.</p>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="list">
            @foreach($presets as $key => $preset)
                <li class="border border-slate-200 rounded-lg p-4 flex flex-col gap-2">
                    <p class="text-sm font-medium text-slate-900">{{ $preset['name'] }}</p>
                    <p class="text-xs text-slate-500 flex-1">{{ $preset['note'] }}</p>
                    <p class="text-xs text-slate-400 break-all">{{ $preset['model'] }}</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('admin.ai-models.create', ['preset' => $key]) }}" class="text-xs font-medium border border-slate-300 rounded-lg px-3 py-1.5 text-slate-700 hover:bg-slate-50">Add</a>
                        <a href="{{ $preset['key_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs text-slate-500 underline hover:text-slate-900">Get a free key<span class="sr-only"> (opens in a new tab)</span></a>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
    @endif

    <section class="bg-white border border-slate-200 rounded-xl p-5 text-sm text-slate-600 space-y-1">
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Related</h2>
        <p>Error alerts: {{ $alertChat ? 'sent to Telegram chat '.$alertChat : 'off (set TELEGRAM_BOT_TOKEN and TELEGRAM_ALERT_CHAT_ID or TELEGRAM_CHAT_ID in .env)' }}</p>
        <p><a href="{{ route('admin.ai-instructions.index') }}" class="underline hover:text-slate-900">AI rules &amp; info</a> · <a href="{{ route('admin.ai-instructions.prompt') }}" class="underline hover:text-slate-900">Preview the prompt</a></p>
    </section>
</div>
@endsection
