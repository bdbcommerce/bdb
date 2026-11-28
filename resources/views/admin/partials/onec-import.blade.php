{{--
    The progress window of "Load items / companies from 1C". Include once on a page; every form with
    data-onec-import="items|companies" then opens it instead of posting (without JavaScript the form still posts
    and loads everything in one request).
--}}
<div x-data="onecImport()" x-show="open" x-cloak @onec-import.window="start($event.detail)" @keydown.escape.window="if (open && ! running) close()"
     class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="onec-import-title">
    <div class="absolute inset-0 bg-slate-900/50" aria-hidden="true"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl border border-slate-200 shadow-xl p-5 sm:p-6 focus:outline-none" x-ref="panel" tabindex="-1" x-trap.inert.noscroll="open">
        <h2 id="onec-import-title" class="text-base font-semibold text-slate-900" x-text="what === 'companies' ? 'Loading companies from 1C' : 'Loading items from 1C'"></h2>

        <div class="mt-4">
            <p class="flex items-baseline justify-between gap-3">
                <span class="text-2xl font-semibold tabular-nums text-slate-900">
                    <span x-text="phase === 'fetch' ? '…' : done"></span><span class="text-base font-normal text-slate-500" x-show="phase !== 'fetch'"> of <span x-text="total"></span></span>
                </span>
                <span class="text-sm tabular-nums text-slate-500" x-show="phase !== 'fetch'" x-text="percent() + '%'"></span>
            </p>
            <p class="text-xs text-slate-500 mt-1" aria-live="polite" x-text="status()"></p>
        </div>

        <div class="mt-3 h-2.5 w-full rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100"
             :aria-valuenow="phase === 'fetch' ? null : percent()" :aria-valuetext="phase === 'fetch' ? 'Asking 1C for the list' : done + ' of ' + total" aria-labelledby="onec-import-title">
            <div class="h-full rounded-full bg-slate-900 transition-[width] duration-300 motion-reduce:transition-none"
                 :class="phase === 'fetch' ? 'w-1/3 animate-pulse motion-reduce:animate-none' : (phase === 'error' || phase === 'stopped' ? 'bg-slate-400' : '')"
                 :style="phase === 'fetch' ? '' : 'width: ' + percent() + '%'"></div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm" x-show="phase !== 'fetch'">
            <template x-for="row in rows()" :key="row.key">
                <div class="flex items-baseline justify-between gap-2 border-b border-slate-100 pb-1">
                    <dt class="text-xs text-slate-500" x-text="row.label"></dt>
                    <dd class="font-medium tabular-nums text-slate-900" x-text="row.n"></dd>
                </div>
            </template>
        </dl>

        <p class="mt-4 text-sm text-slate-800 border border-slate-300 rounded-lg px-3 py-2" role="alert" x-show="error" x-text="error"></p>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" x-show="running && phase === 'save'" @click="stopping = true" :disabled="stopping"
                    class="border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 disabled:opacity-60" x-text="stopping ? 'Stopping…' : 'Stop'"></button>
            <button type="button" x-show="! running" x-ref="closeButton" @click="close()"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Close</button>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
    function onecImport() {
        const urls = {
            items: { start: @json(route('admin.companies.import-start', 'items')), step: @json(route('admin.companies.import-step', 'items')) },
            companies: { start: @json(route('admin.companies.import-start', 'companies')), step: @json(route('admin.companies.import-step', 'companies')) },
        };
        const labels = {
            items: { saved: 'Saved', skipped: 'Skipped (no id, code or name)' },
            companies: { added: 'Companies added', updated: 'Companies updated', private: 'Private persons', other: 'Not loaded (no usable number)' },
        };

        return {
            open: false, running: false, stopping: false, waiting: false, what: 'items', phase: 'fetch', total: 0, done: 0, counts: {}, error: '', opener: null,

            percent() { return this.total ? Math.min(100, Math.floor(this.done * 100 / this.total)) : (this.phase === 'done' ? 100 : 0); },
            rows() { return Object.entries(labels[this.what]).map(([key, label]) => ({ key, label, n: this.counts[key] || 0 })); },
            status() {
                return {
                    fetch: 'Asking 1C for the list… this can take up to a minute.',
                    save: this.waiting ? 'Waiting a little for the server, then it goes on. Keep this window open.' : 'Saving. Keep this window open.',
                    done: this.total ? 'Finished.' : '1C returned an empty list.',
                    stopped: 'Stopped. What was saved so far stays; load again to finish.',
                    error: 'Not finished. What was saved so far stays.',
                }[this.phase];
            },

            async post(url, body, tries) {
                const response = await fetch(url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                               'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify(body || {}),
                });
                let data = null;
                try { data = await response.json(); } catch (e) { /* not JSON */ }
                // Too fast for the server's limit: wait as long as it says (at most a minute) and go on.
                if (response.status === 429 && (tries || 0) < 3) {
                    this.waiting = true;
                    await new Promise((done) => setTimeout(done, Math.min(60, parseInt(response.headers.get('Retry-After'), 10) || 20) * 1000));
                    this.waiting = false;
                    return this.post(url, body, (tries || 0) + 1);
                }
                if (! response.ok) {
                    throw new Error((data && data.message) || ({ 403: 'You are signed out. Reload the page and sign in again.', 419: 'The page is too old. Reload it and try again.', 429: 'Too many loads in a minute. Wait a little and try again.' }[response.status]) || ('The server answered ' + response.status + '.'));
                }
                // A page instead of JSON = we were sent to the sign-in page.
                if (! data) throw new Error('You are signed out or the page is too old. Reload it and try again.');
                return data;
            },

            async start(detail) {
                if (this.running) return;
                Object.assign(this, { open: true, running: true, stopping: false, what: detail.what === 'companies' ? 'companies' : 'items',
                                      phase: 'fetch', total: 0, done: 0, counts: {}, error: '', opener: detail.opener || null });
                this.$nextTick(() => this.$refs.panel.focus());
                try {
                    const list = await this.post(urls[this.what].start);
                    this.total = list.total || 0;
                    this.phase = 'save';
                    while (this.done < this.total && ! this.stopping) {
                        const part = await this.post(urls[this.what].step, { offset: this.done });
                        for (const [key, n] of Object.entries(part.counts || {})) this.counts[key] = (this.counts[key] || 0) + n;
                        this.total = part.total;
                        if (part.done <= this.done) throw new Error('The load stopped moving. Try again.');
                        this.done = part.done;
                    }
                    this.phase = this.done >= this.total ? 'done' : 'stopped';
                } catch (e) {
                    this.error = e instanceof TypeError ? 'The connection was lost. What was saved stays; load again to finish.' : (e.message || 'Something went wrong.');
                    this.phase = 'error';
                }
                this.running = false;
                this.$nextTick(() => this.$refs.closeButton.focus());
            },

            close() {
                this.open = false;
                // Something was saved: show the fresh list.
                if (this.done > 0) { window.location.reload(); return; }
                if (this.opener) this.opener.focus();
            },
        };
    }

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-onec-import]');
        if (! form || ! window.Alpine) return;
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('onec-import', { detail: { what: form.dataset.onecImport, opener: form.querySelector('button') } }));
    });
</script>
@endpush
@endonce
