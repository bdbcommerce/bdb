<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — BDB CMS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    {{-- Alpine.js (with collapse/focus/persist plugins) is bundled by @livewireScripts below. --}}
    <style>[x-cloak]{display:none !important}</style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased">

@php
    /*
     * Admin information architecture.
     * Each item: route (link target), match (route patterns that mark it active), label, icon (Heroicons outline path "d" values).
     * Badge counts must stay cheap (single indexed count query).
     */
    $newLeadsCount = $openRequestsCount = 0;
    try {
        $newLeadsCount = \App\Models\ChatLead::where('is_handled', false)->count();
        $openRequestsCount = \App\Models\ClientRequest::where('status', 'open')->count();
    } catch (\Throwable $e) {
        // Table may not exist yet (e.g. migrations pending) — never break the admin shell.
    }

    $navGroups = [
        [
            'key' => 'main',
            'label' => null,
            'items' => [
                ['route' => 'admin.dashboard', 'match' => ['admin.dashboard'], 'label' => 'Dashboard',
                 'icon' => ['M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z']],
            ],
        ],
        [
            'key' => 'company',
            'label' => 'Company',
            'items' => [
                ['route' => 'admin.company-info.edit', 'match' => ['admin.company-info.*'], 'label' => 'Profile & SEO',
                 'icon' => ['M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z']],
                ['route' => 'admin.banners.index', 'match' => ['admin.banners.*'], 'label' => 'Home banners',
                 'icon' => ['M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z']],
            ],
        ],
        [
            'key' => 'catalog',
            'label' => 'Catalog',
            'items' => [
                ['route' => 'admin.brands.index', 'match' => ['admin.brands.*'], 'label' => 'Brands',
                 'icon' => ['M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z', 'M6 6h.008v.008H6V6z']],
                ['route' => 'admin.industries.index', 'match' => ['admin.industries.*'], 'label' => 'Industries',
                 'icon' => ['M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21']],
                ['route' => 'admin.customers.index', 'match' => ['admin.customers.*'], 'label' => 'Customers',
                 'icon' => ['M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z']],
            ],
        ],
        [
            'key' => 'content',
            'label' => 'Content',
            'items' => [
                ['route' => 'admin.articles.index', 'match' => ['admin.articles.*'], 'label' => 'Articles',
                 'icon' => ['M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z']],
                ['route' => 'admin.article-categories.index', 'match' => ['admin.article-categories.*'], 'label' => 'Categories',
                 'icon' => ['M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776']],
            ],
        ],
        [
            'key' => 'assistant',
            'label' => 'AI Assistant',
            'items' => [
                ['route' => 'admin.client-requests.index', 'match' => ['admin.client-requests.*'], 'label' => 'Client requests',
                 'badge' => $openRequestsCount, 'badge_label' => 'open requests',
                 'icon' => ['M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z']],
                ['route' => 'admin.chat-leads.index', 'match' => ['admin.chat-leads.*'], 'label' => 'Chat leads',
                 'badge' => $newLeadsCount, 'badge_label' => 'new leads',
                 'icon' => ['M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z']],
                ['route' => 'admin.chat-conversations.index', 'match' => ['admin.chat-conversations.*'], 'label' => 'Conversations',
                 'icon' => ['M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155']],
                ['route' => 'admin.knowledge.index', 'match' => ['admin.knowledge.*'], 'label' => 'Knowledge base',
                 'icon' => ['M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25']],
                ['route' => 'admin.telegram.edit', 'match' => ['admin.telegram.*'], 'label' => 'Telegram bot',
                 'icon' => ['M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5']],
            ],
        ],
    ];

    // Resolve active state per item / group, and the breadcrumb.
    $activeGroup = null;
    $activeLabel = null;
    foreach ($navGroups as $gi => $group) {
        $navGroups[$gi]['active'] = false;
        foreach ($group['items'] as $ii => $item) {
            $isActive = request()->routeIs(...$item['match']);
            $navGroups[$gi]['items'][$ii]['active'] = $isActive;
            if ($isActive) {
                $navGroups[$gi]['active'] = true;
                $activeGroup = $group['label'];
                $activeLabel = $item['label'];
            }
        }
    }

    $userName = auth()->user()->name ?? 'Admin';
    $userInitial = strtoupper(mb_substr($userName, 0, 1));
@endphp

<a href="#main-content"
   class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2
          focus:text-sm focus:font-medium focus:text-slate-900 focus:shadow focus:ring-2 focus:ring-slate-400">
    Skip to content
</a>

<div class="flex min-h-screen"
     x-data="{
         sidebarOpen: false,
         isDesktop: true,
         init() {
             const mq = window.matchMedia('(min-width: 1024px)');
             this.isDesktop = mq.matches;
             mq.addEventListener('change', (e) => { this.isDesktop = e.matches; if (e.matches) this.sidebarOpen = false; });
         },
     }"
     @keydown.escape.window="if (sidebarOpen) { sidebarOpen = false; $nextTick(() => $refs.menuButton && $refs.menuButton.focus()); }">

    {{-- Mobile backdrop --}}
    <div x-show="sidebarOpen && ! isDesktop" x-cloak
         x-transition.opacity
         @click="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
         aria-hidden="true"></div>

    @include('admin.partials.sidebar')

    {{-- Main --}}
    <div class="flex-1 flex flex-col min-w-0">
        <header class="sticky top-0 z-20 h-16 bg-white border-b border-slate-200 flex items-center px-4 sm:px-6 shrink-0 gap-3">
            <button type="button" x-ref="menuButton"
                    @click="sidebarOpen = true"
                    :aria-expanded="sidebarOpen.toString()"
                    aria-expanded="false"
                    aria-controls="admin-sidebar"
                    class="lg:hidden -ml-1 p-2 rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900
                           focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 relative">
                <span class="sr-only">Open menu</span>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
                @if ($newLeadsCount > 0)
                    <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-slate-900 ring-2 ring-white" aria-hidden="true"></span>
                @endif
            </button>

            <div class="min-w-0">
                <nav aria-label="Breadcrumb">
                    <p class="text-xs text-slate-400 mb-0.5 truncate">
                        @if ($activeGroup)
                            {{ $activeGroup }} <span class="mx-1 text-slate-300" aria-hidden="true">/</span>
                        @endif
                        {{ $activeLabel ?? 'Admin' }}
                    </p>
                </nav>
                <h1 class="text-base font-semibold text-slate-900 truncate">@yield('heading', 'Dashboard')</h1>
            </div>

            <div class="ml-auto flex items-center gap-4 pl-2 shrink-0">
                @hasSection('header-actions')
                    <div class="flex items-center gap-3">
                        @yield('header-actions')
                    </div>
                    <div class="w-px h-6 bg-slate-200 hidden sm:block"></div>
                @endif

                @auth
                    <div class="relative" x-data="{ open: false }"
                         @click.outside="open = false"
                         @keydown.escape.stop="open = false; $refs.userButton.focus()">
                        <button @click="open = ! open" type="button" x-ref="userButton"
                                aria-haspopup="true"
                                :aria-expanded="open.toString()"
                                aria-expanded="false"
                                aria-controls="admin-user-menu"
                                class="flex items-center gap-2 pl-2 pr-1.5 py-1 rounded-lg hover:bg-slate-100 transition-colors
                                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                            <span class="sr-only">Account menu</span>
                            <span class="w-8 h-8 rounded-full bg-slate-900 text-white text-xs font-semibold flex items-center justify-center shrink-0" aria-hidden="true">
                                {{ $userInitial }}
                            </span>
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                            </svg>
                        </button>
                        <div id="admin-user-menu" x-show="open" x-transition x-cloak
                             class="absolute right-0 mt-2 w-56 bg-white border border-slate-200 rounded-lg shadow-lg py-1 z-20">
                            <div class="px-3 py-2 border-b border-slate-100">
                                <p class="text-sm font-medium text-slate-900 truncate">{{ $userName }}</p>
                                <p class="text-xs text-slate-400 truncate">{{ auth()->user()->email }}</p>
                            </div>
                            <a href="{{ route('site.home', ['locale' => 'uz']) }}" target="_blank" rel="noopener"
                               class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900
                                      focus:outline-none focus-visible:bg-slate-50">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                </svg>
                                View website
                            </a>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit"
                                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-red-600 transition-colors
                                               focus:outline-none focus-visible:bg-slate-50">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                                    </svg>
                                    Sign out
                                </button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>
        </header>

        <main id="main-content" tabindex="-1" class="flex-1 p-4 sm:p-6 focus:outline-none">
            @if (session('success'))
                <div role="status" class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800 flex items-start gap-2.5">
                    <svg class="w-4 h-4 text-green-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ session('success') }}</span>
                </div>
            @endif
            @if ($errors->any())
                <div role="alert" class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800 flex items-start gap-2.5">
                    <svg class="w-4 h-4 text-red-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>

<script src="https://cdn.quilljs.com/1.3.7/quill.js"></script>
@stack('scripts')
@livewireScripts
</body>
</html>
