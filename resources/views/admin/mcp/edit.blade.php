@extends('admin.layout')
@section('title', 'MCP server')
@section('heading', 'MCP server')

@php
    $card = 'bg-white border border-slate-200 rounded-xl p-6 space-y-4';
    $h2 = 'text-xs font-semibold text-slate-500 uppercase tracking-wide';
    $code = 'block w-full overflow-x-auto whitespace-pre rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 font-mono text-xs text-slate-800';
    $token = session('mcp_token');
    $tokenShown = $token ?? '<your token>';
@endphp

@section('content')
<div class="w-full space-y-6">
    @if($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
            <ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="{{ $card }}">
        <div>
            <h2 class="{{ $h2 }}">What it is</h2>
            <p class="text-sm text-slate-600 mt-2">
                The MCP server connects Claude to the CMS. With your team's access token, Claude can read the customer conversations
                (website, Telegram bot, Premium account, groups), find questions the chatbot could not answer, and teach it by adding
                knowledge entries and AI rules. Public tools give any AI app the company's public information (contacts, brands, FAQ, articles).
            </p>
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-slate-500">Status</dt>
            <dd><span class="text-[11px] font-medium rounded-full px-2 py-0.5 {{ $settings->enabled ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-500' }}">{{ $settings->enabled ? 'On' : 'Off' }}</span></dd>
            <dt class="text-slate-500">Address</dt>
            <dd class="break-all font-mono text-xs text-slate-800">{{ $endpoint }}</dd>
            <dt class="text-slate-500">Access</dt>
            <dd class="text-slate-800">
                {{ $settings->require_token ? 'Access token required' : 'Public (no token)' }}
                @if($settings->require_token && $settings->token_hint) · token ends with <code>…{{ $settings->token_hint }}</code> @endif
            </dd>
            <dt class="text-slate-500">Used</dt>
            <dd class="text-slate-800">{{ $settings->calls_count }} {{ Str::plural('request', $settings->calls_count) }}@if($settings->last_used_at), last {{ $settings->last_used_at->diffForHumans() }}@endif</dd>
        </dl>
        @unless($publicHttps)
            <p class="text-xs text-amber-700">Claude can only reach the server on the public https:// address — set APP_URL on the server.</p>
        @endunless
    </div>

    @if($token)
        <div role="alert" class="rounded-xl border-2 border-slate-900 bg-white p-5 space-y-2">
            <p class="text-sm font-semibold text-slate-900">Your new access token — copy it now, it will not be shown again</p>
            <code class="{{ $code }}" x-data x-ref="t">{{ $token }}</code>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.mcp.update') }}" class="{{ $card }}">
        @csrf @method('PUT')
        <h2 class="{{ $h2 }}">Settings</h2>
        <label class="flex items-start gap-2 text-sm">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled)) class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
            <span>Turn on the MCP server <span class="block text-xs text-slate-500">When off, {{ $endpoint }} answers “not found”.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm">
            <input type="hidden" name="require_token" value="0">
            <input type="checkbox" name="require_token" value="1" @checked(old('require_token', $settings->require_token)) class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
            <span>Require an access token
                <span class="block text-xs text-slate-500">For your team's Claude (Claude Code, Claude Desktop). Untick to let any AI app connect — e.g. a Claude.ai custom connector without sign-in; the data is public anyway.</span></span>
        </label>
        <div>
            <label for="rate_limit" class="block text-sm font-medium text-slate-700 mb-1">Requests per minute per client</label>
            <input id="rate_limit" name="rate_limit" type="number" min="5" max="600" value="{{ old('rate_limit', $settings->rate_limit) }}"
                   class="w-32 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
        </div>
        <fieldset>
            <legend class="text-sm font-medium text-slate-700 mb-1">Team tools — learn from conversations and teach the chatbot</legend>
            <p class="text-xs text-slate-500 mb-2">Only with an access token (never on a public server). Claude reads the customer chats (website, Telegram bot, Premium account, groups — without phone numbers) and adds knowledge entries and AI rules.</p>
            @unless($settings->require_token)
                <p class="text-xs text-red-700 mb-2">Off while “Require an access token” is unticked.</p>
            @endunless
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach($teamTools as $tool => [$label, $description, $writes])
                    <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-3 text-sm">
                        <input type="hidden" name="tools[{{ $tool }}]" value="0">
                        <input type="checkbox" name="tools[{{ $tool }}]" value="1" @checked(old('tools.'.$tool, $settings->tools[$tool] ?? true))
                               class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                        <span>
                            <span class="font-medium text-slate-900">{{ $label }}</span> <code class="text-xs text-slate-500">{{ $tool }}</code>
                            @if($writes)<span class="ml-1 text-[11px] font-medium rounded-full border border-slate-300 px-1.5 text-slate-600">writes</span>@endif
                            <span class="block text-xs text-slate-500">{{ $description }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <label class="mt-3 flex items-start gap-2 text-sm">
                <input type="hidden" name="autopublish" value="0">
                <input type="checkbox" name="autopublish" value="1" @checked(old('autopublish', $settings->autopublish)) class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                <span>Publish what Claude adds right away
                    <span class="block text-xs text-slate-500">Unticked (recommended): new knowledge entries and AI rules arrive as drafts — review and activate them in Knowledge base / AI rules &amp; info.</span></span>
            </label>
        </fieldset>
        <fieldset>
            <legend class="text-sm font-medium text-slate-700 mb-2">Public tools</legend>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach($tools as $tool => [$label, $description])
                    <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-3 text-sm">
                        <input type="hidden" name="tools[{{ $tool }}]" value="0">
                        <input type="checkbox" name="tools[{{ $tool }}]" value="1" @checked(old('tools.'.$tool, $settings->toolEnabled($tool)))
                               class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                        <span>
                            <span class="font-medium text-slate-900">{{ $label }}</span> <code class="text-xs text-slate-500">{{ $tool }}</code>
                            <span class="block text-xs text-slate-500">{{ $description }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-slate-400 mt-2">The FAQ tool uses the knowledge types chosen for the public FAQ in <a href="{{ route('admin.seo.edit') }}" class="underline">SEO &amp; AI visibility</a>.</p>
        </fieldset>
        <div class="flex justify-end">
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-6 py-2.5 hover:bg-slate-700 transition-colors">Save</button>
        </div>
    </form>

    @if($settings->require_token)
        <form method="POST" action="{{ route('admin.mcp.token') }}" class="{{ $card }}"
              onsubmit="return confirm('Create a new token? Apps using the old one will stop working.')">
            @csrf
            <h2 class="{{ $h2 }}">Access token</h2>
            <p class="text-sm text-slate-600">{{ $settings->token_hint ? 'A token exists (ends with …'.$settings->token_hint.').' : 'No token yet.' }} Tokens are stored encrypted one-way, so a lost token can't be shown again — create a new one.</p>
            <button class="inline-flex items-center gap-2 border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50">{{ $settings->token_hint ? 'Create a new token' : 'Create token' }}</button>
        </form>
    @endif

    <div class="{{ $card }}">
        <h2 class="{{ $h2 }}">How to connect</h2>
        <div class="space-y-1">
            <p class="text-sm font-medium text-slate-800">Claude Code</p>
            <code class="{{ $code }}">claude mcp add --transport http bdb {{ $endpoint }}@if($settings->require_token) --header "Authorization: Bearer {{ $tokenShown }}"@endif</code>
        </div>
        <div class="space-y-1">
            <p class="text-sm font-medium text-slate-800">Claude.ai / Claude Desktop (Settings → Connectors → Add custom connector)</p>
            <p class="text-xs text-slate-500">
                Server URL: <code class="break-all">{{ $endpoint }}</code>.
                @if($settings->require_token)
                    Custom connectors there connect without a token — untick “Require an access token” to use them.
                @endif
            </p>
        </div>
        <div class="space-y-1">
            <p class="text-sm font-medium text-slate-800">Any MCP client (JSON config)</p>
            <code class="{{ $code }}">{{ json_encode(['mcpServers' => ['bdb' => array_filter(['type' => 'http', 'url' => $endpoint, 'headers' => $settings->require_token ? ['Authorization' => 'Bearer '.$tokenShown] : null])]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code>
        </div>
    </div>
</div>
@endsection
