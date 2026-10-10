{{-- Belgi — AI chat assistant widget --}}
@php
    $chatLocale = app()->getLocale();
    $routeName  = request()->route()?->getName();
    $teasers    = __('chat.teasers');
@endphp

<div x-data="belgiChat(@js([
        'locale'    => $chatLocale,
        'page'      => trim(View::yieldContent('title', 'BDB')),
        'csrf'      => csrf_token(),
        'endpoint'  => route('chat.message'),
        'leadUrl'   => route('chat.lead'),
        'welcome'   => __('chat.welcome'),
        'error'     => __('chat.error'),
        'thanks'    => __('chat.thanks'),
        'starters'  => __('chat.starters'),
        'teaser'    => $teasers[$routeName] ?? $teasers['default'],
    ]))"
     x-init="init()"
     @keydown.escape.window="open = false"
     @belgi-open.window="open = true; touched = true; teaserVisible = false; persist(); if ($event.detail && $event.detail.message) $nextTick(() => send($event.detail.message))"
     class="fixed bottom-4 right-4 sm:bottom-6 sm:right-6 z-[60] flex flex-col items-end gap-3 print:hidden">

    {{-- Proactive teaser bubble --}}
    <div x-show="teaserVisible && !open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="relative max-w-[260px] bg-white border border-gray-200 shadow-lg rounded-2xl rounded-br-sm px-4 py-3 text-sm text-gray-700">
        <button type="button" @click="dismissTeaser()" aria-label="{{ __('chat.close') }}"
                class="absolute -top-2 -left-2 w-6 h-6 rounded-full bg-white border border-gray-200 text-gray-400 hover:text-gray-700 flex items-center justify-center">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <button type="button" @click="toggle()" class="text-left" x-text="cfg.teaser"></button>
    </div>

    {{-- Chat window --}}
    <section x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-4 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 translate-y-4"
             role="dialog" aria-label="{{ __('chat.title') }}"
             class="w-[calc(100vw-2rem)] sm:w-[380px] h-[min(600px,calc(100vh-7rem))] bg-white border border-gray-200 rounded-2xl shadow-2xl flex flex-col overflow-hidden">

        {{-- Header --}}
        <header class="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
            <div class="relative w-9 h-9 rounded-full bg-gray-900 text-white flex items-center justify-center shrink-0">
                {{-- barcode mark --}}
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <path d="M4 6v12M7 6v12M9.5 6v12M13 6v12M15 6v12M18 6v12M20 6v12"/>
                </svg>
                <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-green-500 ring-2 ring-white"></span>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900 truncate">{{ __('chat.title') }}</p>
                <p class="text-xs text-gray-500 truncate">{{ __('chat.status') }}</p>
            </div>
            <button type="button" @click="reset()" title="{{ __('chat.reset') }}" aria-label="{{ __('chat.reset') }}"
                    class="p-2 rounded-lg text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
            </button>
            <button type="button" @click="open = false" title="{{ __('chat.close') }}" aria-label="{{ __('chat.close') }}"
                    class="p-2 rounded-lg text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
            </button>
        </header>

        {{-- Messages --}}
        <div x-ref="scroller" class="flex-1 overflow-y-auto px-4 py-4 space-y-3 bg-gray-50/60" aria-live="polite">
            {{-- Welcome --}}
            <div class="flex">
                <div class="max-w-[85%] bg-white border border-gray-200 rounded-2xl rounded-tl-sm px-3.5 py-2.5 text-sm text-gray-800" x-text="cfg.welcome"></div>
            </div>

            <template x-for="(m, i) in messages" :key="i">
                <div class="flex" :class="m.role === 'user' ? 'justify-end' : ''">
                    <div class="max-w-[85%] px-3.5 py-2.5 text-sm rounded-2xl whitespace-pre-line break-words"
                         :class="m.role === 'user'
                            ? 'bg-gray-900 text-white rounded-tr-sm'
                            : 'bg-white border border-gray-200 text-gray-800 rounded-tl-sm [&_a]:underline [&_a]:text-red-600'"
                         x-html="format(m.content)"></div>
                </div>
            </template>

            {{-- "Scanning" typing indicator --}}
            <div x-show="loading" class="flex">
                <div class="bg-white border border-gray-200 rounded-2xl rounded-tl-sm px-4 py-3">
                    <div class="belgi-scan relative h-5 w-16 overflow-hidden" aria-hidden="true">
                        <svg class="absolute inset-0 w-full h-full text-gray-300" viewBox="0 0 64 20" preserveAspectRatio="none" fill="currentColor">
                            <rect x="0" width="2" height="20"/><rect x="4" width="1" height="20"/><rect x="7" width="3" height="20"/>
                            <rect x="12" width="1" height="20"/><rect x="15" width="2" height="20"/><rect x="19" width="1" height="20"/>
                            <rect x="22" width="3" height="20"/><rect x="27" width="1" height="20"/><rect x="30" width="2" height="20"/>
                            <rect x="34" width="1" height="20"/><rect x="37" width="3" height="20"/><rect x="42" width="1" height="20"/>
                            <rect x="45" width="2" height="20"/><rect x="49" width="1" height="20"/><rect x="52" width="3" height="20"/>
                            <rect x="57" width="1" height="20"/><rect x="60" width="2" height="20"/><rect x="63" width="1" height="20"/>
                        </svg>
                        <span class="belgi-laser absolute top-0 bottom-0 w-0.5 bg-red-500 shadow-[0_0_6px_1px_rgba(239,68,68,0.7)]"></span>
                    </div>
                </div>
            </div>

            {{-- Callback offer: the form opens only when the visitor asks for it --}}
            <div x-show="callbackOffered && !showLead && !loading" x-cloak class="flex">
                <button type="button" @click="openLead()"
                        class="inline-flex items-center gap-2 bg-red-600 text-white text-sm font-medium rounded-full px-4 py-2 hover:bg-red-700 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                    {{ __('chat.callback_title') }}
                </button>
            </div>

            {{-- Callback form --}}
            <form x-show="showLead" x-cloak @submit.prevent="submitLead()"
                  class="bg-white border border-gray-200 rounded-2xl p-4 space-y-2.5">
                <p class="text-sm font-semibold text-gray-900">{{ __('chat.callback_title') }}</p>
                <p class="text-xs text-gray-500">{{ __('chat.callback_text') }}</p>
                <input type="text" x-model="lead.name" required maxlength="100" placeholder="{{ __('chat.name') }}" autocomplete="name"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400">
                <input type="tel" x-model="lead.phone" required maxlength="30" placeholder="+998 __ ___ __ __" autocomplete="tel"
                       pattern="[0-9+()\-\s]{7,30}" aria-label="{{ __('chat.phone') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400">
                <input type="text" x-model="lead.topic" maxlength="500" placeholder="{{ __('chat.topic') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400">
                <p x-show="leadError" x-text="leadError" role="alert" class="text-xs text-red-600"></p>
                <div class="flex gap-2 pt-1">
                    <button type="submit" :disabled="leadSending"
                            class="flex-1 bg-red-600 text-white text-sm font-medium rounded-lg px-3 py-2 hover:bg-red-700 disabled:opacity-60 transition-colors">{{ __('chat.submit') }}</button>
                    <button type="button" @click="showLead = false; leadError = ''"
                            class="text-sm text-gray-500 hover:text-gray-900 px-3">{{ __('chat.cancel') }}</button>
                </div>
                <p class="text-[11px] leading-snug text-gray-400">{{ __('chat.consent') }}</p>
            </form>
        </div>

        {{-- Chips --}}
        <div x-show="chips().length && !loading" class="flex gap-2 overflow-x-auto px-4 pt-3 pb-1 border-t border-gray-100">
            <template x-for="chip in chips()" :key="chip">
                <button type="button" @click="send(chip)"
                        class="shrink-0 text-xs text-gray-700 border border-gray-300 rounded-full px-3 py-1.5 hover:border-gray-900 hover:text-gray-900 transition-colors"
                        x-text="chip"></button>
            </template>
            <button type="button" x-show="!showLead" @click="openLead()"
                    class="shrink-0 inline-flex items-center gap-1 text-xs text-red-600 border border-red-200 rounded-full px-3 py-1.5 hover:bg-red-50 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                {{ __('chat.human') }}
            </button>
        </div>

        {{-- Input --}}
        <form @submit.prevent="send()" class="flex items-end gap-2 px-3 py-3">
            <textarea x-ref="input" x-model="draft" rows="1" maxlength="1000"
                      @keydown.enter.prevent="if (!$event.shiftKey) send(); else draft += '\n'"
                      @input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 112) + 'px'"
                      placeholder="{{ __('chat.placeholder') }}" aria-label="{{ __('chat.placeholder') }}"
                      class="flex-1 resize-none border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400 max-h-28"></textarea>
            <button type="submit" :disabled="loading || !draft.trim()" aria-label="{{ __('chat.send') }}"
                    class="w-10 h-10 shrink-0 rounded-xl bg-gray-900 text-white flex items-center justify-center hover:bg-gray-700 disabled:opacity-40 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
            </button>
        </form>
        <p class="text-[10px] text-gray-400 text-center px-4 pb-2 -mt-1">{{ __('chat.disclaimer') }}</p>
    </section>

    {{-- Launcher --}}
    <button type="button" @click="toggle()" :aria-expanded="open.toString()" aria-label="{{ __('chat.launcher') }}"
            class="group relative w-14 h-14 rounded-full bg-gray-900 text-white shadow-lg hover:bg-gray-800 flex items-center justify-center transition-transform hover:scale-105">
        <span x-show="!open && !touched" class="absolute inset-0 rounded-full bg-gray-900 animate-ping opacity-20"></span>
        <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
        <svg x-show="open" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
</div>

<style>
    [x-cloak] { display: none !important; }
    .belgi-laser { animation: belgi-scan 1.1s ease-in-out infinite alternate; }
    @keyframes belgi-scan { from { left: 0; } to { left: calc(100% - 2px); } }
    @media (prefers-reduced-motion: reduce) { .belgi-laser { animation: none; left: 50%; } }
</style>

<script>
    function belgiChat(cfg) {
        const STORE = 'belgi-chat';
        const read = (key, fallback) => { try { return JSON.parse(sessionStorage.getItem(key)) ?? fallback; } catch (e) { return fallback; } };
        const write = (key, value) => { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) {} };
        // crypto.randomUUID needs HTTPS; fall back to getRandomValues on plain-HTTP dev hosts.
        const uuid = () => crypto.randomUUID ? crypto.randomUUID()
            : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));

        return {
            cfg,
            open: false,
            touched: false,
            teaserVisible: false,
            draft: '',
            loading: false,
            messages: [],
            suggestions: [],
            showLead: false,
            leadSending: false,
            leadError: '',
            callbackOffered: false,
            conversation: null,
            lead: { name: '', phone: '', topic: '' },

            init() {
                const saved = read(STORE, {});
                this.conversation = saved.conversation || uuid();
                this.messages = saved.messages || [];
                this.suggestions = saved.suggestions || [];
                this.callbackOffered = !!saved.callbackOffered;
                this.open = !!saved.open;
                this.touched = !!saved.touched;

                // Page-aware nudge after a few seconds, once per session.
                if (!this.touched && !read(STORE + '-teased', false)) {
                    setTimeout(() => {
                        if (!this.open) { this.teaserVisible = true; write(STORE + '-teased', true); }
                    }, 6000);
                }
                this.$watch('open', (v) => { this.persist(); if (v) this.$nextTick(() => { this.scroll(); this.$refs.input.focus(); }); });
                if (this.open) this.$nextTick(() => this.scroll());
            },

            persist() {
                write(STORE, {
                    conversation: this.conversation, messages: this.messages.slice(-20), suggestions: this.suggestions,
                    callbackOffered: this.callbackOffered, open: this.open, touched: this.touched,
                });
            },

            toggle() {
                this.open = !this.open;
                this.touched = true;
                this.teaserVisible = false;
            },

            dismissTeaser() {
                this.teaserVisible = false;
                this.touched = true;
                this.persist();
            },

            chips() {
                return this.messages.length ? this.suggestions : this.cfg.starters;
            },

            reset() {
                this.conversation = uuid();
                this.messages = [];
                this.suggestions = [];
                this.showLead = false;
                this.callbackOffered = false;
                this.persist();
            },

            scroll() {
                this.$nextTick(() => { const el = this.$refs.scroller; el.scrollTop = el.scrollHeight; });
            },

            openLead() {
                this.showLead = true;
                if (!this.lead.topic) {
                    const lastUser = [...this.messages].reverse().find(m => m.role === 'user');
                    if (lastUser) this.lead.topic = lastUser.content.slice(0, 500);
                }
                this.scroll();
            },

            async send(text) {
                const content = (text ?? this.draft).trim();
                if (!content || this.loading) return;

                this.draft = '';
                this.$refs.input.style.height = 'auto';
                this.messages.push({ role: 'user', content });
                this.suggestions = [];
                this.loading = true;
                this.scroll();

                try {
                    const res = await fetch(this.cfg.endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf },
                        body: JSON.stringify({ locale: this.cfg.locale, conversation: this.conversation, page: this.cfg.page, messages: this.messages.slice(-12) }),
                    });
                    if (!res.ok) throw new Error(res.status);
                    const data = await res.json();
                    this.messages.push({ role: 'assistant', content: data.reply });
                    this.suggestions = data.suggestions || [];
                    if (data.offer_callback) this.callbackOffered = true;
                } catch (e) {
                    this.messages.push({ role: 'assistant', content: this.cfg.error });
                    this.callbackOffered = true;
                } finally {
                    this.loading = false;
                    this.persist();
                    this.scroll();
                }
            },

            async submitLead() {
                this.leadSending = true;
                this.leadError = '';
                try {
                    const res = await fetch(this.cfg.leadUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf },
                        body: JSON.stringify({ locale: this.cfg.locale, conversation: this.conversation, page_url: location.href, ...this.lead, messages: this.messages.slice(-20) }),
                    });
                    // Validation problem: keep the form open and say what to fix.
                    if (res.status === 422) {
                        const body = await res.json().catch(() => ({}));
                        this.leadError = Object.values(body.errors || {})[0]?.[0] || this.cfg.error;
                        return;
                    }
                    if (!res.ok) throw new Error(res.status);
                    this.showLead = false;
                    this.callbackOffered = false;
                    this.lead = { name: '', phone: '', topic: '' };
                    this.messages.push({ role: 'assistant', content: this.cfg.thanks });
                } catch (e) {
                    this.messages.push({ role: 'assistant', content: this.cfg.error });
                } finally {
                    this.leadSending = false;
                    this.persist();
                    this.scroll();
                }
            },

            // Escape HTML, then turn URLs into links. Model output is never inserted raw.
            format(text) {
                const esc = String(text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                return esc.replace(/https?:\/\/[^\s<]+[^\s<.,;:!?)]/g, url => `<a href="${url}" target="_blank" rel="noopener">${url}</a>`);
            },
        };
    }
</script>
