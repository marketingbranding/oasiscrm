<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#000000">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="OASIS">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <title>@yield('title', 'Oasis CRM')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @if(is_file(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>body{font-family:Arial,sans-serif;margin:0}.asset-warning{padding:8px;background:#fcc20f;border:2px solid #000}</style>
    @endif
    <script>!function(){try{if(localStorage.getItem('oasis.sidebar.collapsed')==='true'){document.documentElement.classList.add('oasis-sidebar-collapsed')}}catch(e){}}()</script>
    <style>
        [x-cloak] { display: none !important; }
        @media (max-width: 767px) {
            button, select, input, textarea, a, [class*="py-1"] { min-height: 44px !important; font-size: 16px !important; }
            table th, table td { padding: 6px 4px !important; font-size: 12px !important; }
            .filter-bar { flex-direction: column !important; gap: 0.5rem !important; }
            .filter-bar select, .filter-bar input { width: 100% !important; }
        }
        @media (min-width: 768px) and (max-width: 1024px) {
            .filter-bar { flex-direction: column !important; gap: 0.5rem !important; }
            .filter-bar select, .filter-bar input { width: 100% !important; }
            table th, table td { padding: 5px 4px !important; font-size: 12px !important; }
        }
    </style>
</head>
<body class="flex min-h-screen flex-col bg-[var(--oasis-page-bg)] font-['Times_New_Roman'] antialiased"
      x-data="crmShell(@js(['activeGroups' => collect($navigation)->where('active', true)->pluck('key')->values()]))">
    @if(request()->boolean('reminder_dismiss_failed'))
        <script>history.replaceState(null, '', new URL(location.href).pathname + new URL(location.href).search.replace(/([?&])reminder_dismiss_failed=1(&|$)/, '$1').replace(/[?&]$/, '') + location.hash)</script>
    @endif

    <x-crm.topbar :$navigation :$overdueItems :$todayItems :$tomorrowItems :$needsConfirmation />

    <div x-show="sidebarOpen" @click="closeMobileNavigation()" class="fixed inset-0 z-[55] bg-black/40 md:hidden" x-cloak></div>

    <div class="crm-shell-frame flex min-h-screen flex-col md:flex-row">
        <aside id="crm-sidebar" x-ref="drawer" :class="sidebarOpen ? 'is-open' : ''"
               class="crm-sidebar fixed bottom-0 left-0 z-[60] flex flex-col border-r-2 border-black shadow-xl md:z-40 md:shadow-none"
               aria-label="Navigasi utama" aria-labelledby="crm-drawer-title"
               :role="mobileViewport ? 'dialog' : null" :aria-modal="mobileViewport && sidebarOpen ? 'true' : null"
               :aria-hidden="mobileViewport && !sidebarOpen ? 'true' : null" :inert="mobileViewport && !sidebarOpen"
               @keydown="handleDrawerKeydown($event)">
            <div class="flex min-h-[var(--oasis-topbar-height)] items-center justify-between border-b-2 border-black bg-black px-4 text-white md:hidden">
                <div>
                    <div id="crm-drawer-title" class="font-[Helvetica] text-sm font-bold tracking-wide">OASIS CRM</div>
                    <div class="font-[Helvetica] text-[10px] font-normal uppercase tracking-[0.16em] text-gray-300">Area kerja</div>
                </div>
                <button type="button" x-ref="drawerClose" @click="closeMobileNavigation()"
                        class="flex size-11 items-center justify-center hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--oasis-yellow)]"
                        aria-label="Tutup navigasi utama">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            </div>
            <nav class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto py-3" aria-label="Area kerja OASIS">
                @foreach($navigation as $group)
                    @if($group['direct'])
                        @php($child = $group['children'][0])
                        <a href="{{ route($child['route']) }}" @click="closeMobileNavigation(false)" class="crm-nav-item {{ $child['active'] ? 'is-active' : '' }}"
                           style="--nav-accent: var(--oasis-accent-{{ $child['accent'] }})" title="{{ $child['label'] }}"
                           @if($child['active']) aria-current="page" @endif>
                            <x-crm.nav-icon :name="$child['icon']" />
                            <span class="crm-sidebar-label">{{ $child['label'] }}</span>
                        </a>
                    @else
                        <section class="border-b border-[var(--oasis-border)] py-1" aria-labelledby="crm-nav-group-{{ $group['key'] }}">
                            <button type="button" id="crm-nav-group-{{ $group['key'] }}"
                                    class="crm-nav-group-toggle {{ $group['active'] ? 'is-active' : '' }}"
                                    @click="toggleGroup('{{ $group['key'] }}')" :aria-expanded="isGroupOpen('{{ $group['key'] }}')"
                                    aria-controls="crm-nav-children-{{ $group['key'] }}" title="{{ $group['label'] }}">
                                <x-crm.nav-icon :name="$group['icon']" />
                                <span class="crm-sidebar-label flex-1">{{ $group['label'] }}</span>
                                <svg class="crm-sidebar-chevron size-4 transition-transform duration-150" :class="isGroupOpen('{{ $group['key'] }}') ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m7 4 6 6-6 6" /></svg>
                            </button>
                            <div id="crm-nav-children-{{ $group['key'] }}" class="crm-nav-children" x-show="isGroupOpen('{{ $group['key'] }}')" x-cloak>
                                @foreach($group['children'] as $child)
                                    <a href="{{ route($child['route']) }}" @click="closeMobileNavigation(false)" class="crm-nav-item {{ $child['active'] ? 'is-active' : '' }}"
                                       style="--nav-accent: var(--oasis-accent-{{ $child['accent'] }})" title="{{ $child['label'] }}"
                                       @if($child['active']) aria-current="page" @endif>
                                        <x-crm.nav-icon :name="$child['icon']" class="size-[18px]" />
                                        <span class="crm-sidebar-label">{{ $child['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            </nav>
            <div class="border-t-2 border-black p-2">
                <button type="button" x-ref="desktopSidebarToggle" @click="toggleDesktopSidebar()"
                        class="crm-sidebar-toggle hidden min-h-11 w-full items-center justify-center gap-3 px-2 font-[Helvetica] text-xs font-bold hover:bg-[var(--oasis-surface-muted)] md:flex"
                        :title="sidebarCollapsed ? 'Perluas sidebar' : 'Ringkas sidebar'"
                        :aria-label="sidebarCollapsed ? 'Perluas sidebar' : 'Ringkas sidebar'">
                    <svg class="size-5 transition-transform duration-150" :class="sidebarCollapsed ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 5-7 7 7 7" /></svg>
                    <span class="crm-sidebar-label">Ringkas sidebar</span>
                </button>
            </div>
        </aside>

        <div x-data="crmToasts(@js([
                 ['type' => 'success', 'message' => session('success')],
                 ['type' => 'error', 'message' => session('error')],
                 ['type' => 'warning', 'message' => session('warning')],
             ]))" @oasis:toast.window="push($event.detail)"
             class="fixed right-4 top-16 z-[999] flex max-w-sm flex-col gap-2 pointer-events-none" aria-live="polite" aria-atomic="false">
            <template x-for="toast in toasts" :key="toast.id">
                <div x-show="toast.show" x-transition:leave="transition ease-out duration-500" x-transition:leave-start="opacity-100 translate-x-0" x-transition:leave-end="opacity-0 translate-x-4"
                     :class="{ 'crm-toast--success': toast.type === 'success', 'crm-toast--error': toast.type === 'error', 'crm-toast--warning': toast.type === 'warning' }"
                     :role="toast.type === 'error' ? 'alert' : 'status'"
                     class="crm-toast pointer-events-auto">
                    <span x-text="toast.message"></span>
                    <button type="button" @click="dismiss(toast.id)" class="shrink-0 text-lg font-bold leading-none text-black/60 hover:text-black" aria-label="Tutup notifikasi">&times;</button>
                </div>
            </template>
        </div>

        <main id="crm-main" class="crm-main flex-1 overflow-x-hidden">
            <div class="crm-page-shell">
                @hasSection('breadcrumbs')
                    <nav class="crm-page-breadcrumbs" aria-label="Breadcrumb">
                        @yield('breadcrumbs')
                    </nav>
                @endif

                @php($hasPageHeader = app('view')->hasSection('page-title') || app('view')->hasSection('page-description') || app('view')->hasSection('page-actions'))
                @if($hasPageHeader)
                    <header class="crm-page-heading">
                        <div class="min-w-0">
                            @hasSection('page-title')
                                <h1 class="crm-page-title">@yield('page-title')</h1>
                            @endif
                            @hasSection('page-description')
                                <div class="crm-page-description">@yield('page-description')</div>
                            @endif
                        </div>
                        @hasSection('page-actions')
                            <div class="crm-page-actions">@yield('page-actions')</div>
                        @endif
                    </header>
                @endif

                @hasSection('page-tabs')
                    <div class="crm-page-tabs crm-horizontal-tabs" data-horizontal-tabs>@yield('page-tabs')</div>
                @endif

                @hasSection('toolbar')
                    <div class="crm-page-toolbar">@yield('toolbar')</div>
                @endif

                <div class="crm-page-body">@yield('content')</div>
            </div>
            <footer class="mt-6 border-t-2 border-black bg-white px-4 py-3 text-center text-xs">&copy; {{ date('Y') }} Oasis CRM</footer>
        </main>
    </div>

    <x-conflict-dialog :initial="session('conflict_data')" />
    <x-crm.feedback-bubble />
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('crmDetailModal', function (fetchBase, editBase, statusColors) {
                return {
                    open: false,
                    loading: false,
                    error: false,
                    taskId: null,
                    task: null,
                    sc: statusColors || {},
                    openDetail: function (id) {
                        this.open = true;
                        this.taskId = id;
                        this.loadDetail(id);
                    },
                    loadDetail: function (id) {
                        this.loading = true;
                        this.error = false;
                        this.task = null;
                        var self = this;
                        fetch(fetchBase + '/' + id + '/detail')
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error('http');
                                }
                                return response.json();
                            })
                            .then(function (data) { self.task = data; self.loading = false; })
                            .catch(function () {
                                self.loading = false;
                                self.error = true;
                                window.oasisToast?.('Gagal memuat detail. Silakan coba lagi.', 'error');
                            });
                    },
                    retry: function () {
                        if (this.taskId) {
                            this.loadDetail(this.taskId);
                        }
                    },
                    close: function () {
                        this.open = false;
                        this.loading = false;
                        this.error = false;
                        this.taskId = null;
                        this.task = null;
                    },
                    get editUrl() { return this.task ? editBase + '/' + this.task.id + '/edit' : '#'; }
                };
            });
        });
    </script>
    @stack('scripts')
    <x-pwa-control />
</body>
</html>
