{{--
    Admin sidebar navigation.
    Expects: $navGroups (array, built in admin/layout.blade.php), $userName, $userInitial.
    Desktop (lg+): static, sticky column. Mobile: off-canvas drawer driven by `sidebarOpen`
    on the parent x-data in the layout.
--}}
<aside id="admin-sidebar"
       aria-label="Admin navigation"
       x-effect="$el.inert = !sidebarOpen && !isDesktop"
       x-trap.noscroll="sidebarOpen && !isDesktop"
       :role="isDesktop ? null : 'dialog'"
       :aria-modal="(!isDesktop && sidebarOpen) ? 'true' : null"
       :class="{ '-translate-x-full': !sidebarOpen }"
       class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full bg-white border-r border-slate-200 flex flex-col
              transition-transform duration-200 ease-out
              lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:w-64 lg:translate-x-0 lg:shrink-0">

    {{-- Brand --}}
    <div class="h-16 flex items-center px-5 border-b border-slate-200 gap-3 shrink-0">
        <a href="{{ route('admin.dashboard') }}"
           class="flex items-center gap-3 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
            <img src="/images/logo.jpg" alt="BDB" class="h-7 w-auto">
            <span class="text-sm font-semibold tracking-tight text-slate-600">Admin</span>
        </a>
        <button type="button"
                @click="sidebarOpen = false"
                class="ml-auto lg:hidden -mr-1 p-2 rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
            <span class="sr-only">Close menu</span>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto py-4 px-3" aria-label="Main">
        @foreach ($navGroups as $group)
            @if (! $group['label'])
                {{-- Ungrouped top-level items (Dashboard) --}}
                <ul class="space-y-0.5" role="list">
                    @foreach ($group['items'] as $item)
                        @include('admin.partials.sidebar-link', ['item' => $item])
                    @endforeach
                </ul>
            @else
                @php
                    $groupId = 'nav-group-' . $group['key'];
                    $groupBadge = collect($group['items'])->sum(fn ($i) => $i['badge'] ?? 0);
                @endphp
                <div class="mt-5"
                     x-data="{
                         open: true,
                         key: @js('bdb.admin.nav.' . $group['key']),
                         forced: @js($group['active']),
                         init() {
                             if (! this.forced) {
                                 try {
                                     const saved = localStorage.getItem(this.key);
                                     if (saved !== null) this.open = saved === '1';
                                 } catch (e) {}
                             }
                             this.$watch('open', (value) => {
                                 try { localStorage.setItem(this.key, value ? '1' : '0'); } catch (e) {}
                             });
                         },
                     }">
                    <h2>
                        <button type="button"
                                @click="open = ! open"
                                :aria-expanded="open.toString()"
                                aria-expanded="true"
                                aria-controls="{{ $groupId }}"
                                class="w-full flex items-center gap-2 px-3 py-1.5 rounded-md text-[11px] font-semibold uppercase tracking-widest
                                       {{ $group['active'] ? 'text-slate-700' : 'text-slate-400' }}
                                       hover:text-slate-700 hover:bg-slate-50
                                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                            <span class="flex-1 text-left">{{ $group['label'] }}</span>
                            @if ($groupBadge > 0)
                                <span x-show="! open" x-cloak
                                      class="min-w-5 h-5 px-1.5 rounded-full bg-slate-900 text-white text-[10px] font-semibold tracking-normal
                                             inline-flex items-center justify-center">
                                    {{ $groupBadge > 99 ? '99+' : $groupBadge }}
                                    <span class="sr-only">new</span>
                                </span>
                            @endif
                            <svg class="w-3.5 h-3.5 shrink-0 transition-transform duration-150"
                                 :class="{ '-rotate-90': ! open }"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                            </svg>
                        </button>
                    </h2>
                    <ul id="{{ $groupId }}" x-show="open" x-collapse role="list"
                        class="mt-1 space-y-0.5 border-t border-slate-100 pt-1.5">
                        @foreach ($group['items'] as $item)
                            @include('admin.partials.sidebar-link', ['item' => $item])
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </nav>

    {{-- Account --}}
    <div class="shrink-0 border-t border-slate-200 p-3 space-y-0.5">
        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-widest text-slate-400">Account</p>
        <a href="{{ route('site.home', ['locale' => 'uz']) }}" target="_blank" rel="noopener"
           class="group flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900
                  focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
            <svg class="w-[18px] h-[18px] shrink-0 text-slate-400 group-hover:text-slate-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
            </svg>
            <span>View website</span>
            <span class="sr-only">(opens in a new tab)</span>
        </a>
        <div class="flex items-center gap-3 px-3 py-2">
            <span class="w-7 h-7 rounded-full bg-slate-900 text-white text-xs font-semibold flex items-center justify-center shrink-0" aria-hidden="true">
                {{ $userInitial }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-900 truncate">{{ $userName }}</p>
                <p class="text-xs text-slate-400 truncate">{{ auth()->user()->email ?? '' }}</p>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" title="Sign out"
                        class="p-1.5 rounded-md text-slate-400 hover:text-slate-900 hover:bg-slate-100
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                    <span class="sr-only">Sign out</span>
                    <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>
