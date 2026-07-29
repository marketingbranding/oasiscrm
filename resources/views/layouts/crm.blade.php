<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Oasis CRM')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @if(is_file(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>body{font-family:Arial,sans-serif;margin:0}.asset-warning{padding:8px;background:#fcc20f;border:2px solid #000}</style>
    @endif
    <script>!function(){if(localStorage.getItem('oasis.sidebar.collapsed')==='true'){document.documentElement.classList.add('oasis-sidebar-collapsed')}}()</script>
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
@php
    $isSalesNavigation = Auth::user()?->isSales() ?? false;
@endphp
<body class="flex min-h-screen flex-col bg-[var(--oasis-page-bg)] font-['Times_New_Roman'] antialiased"
      x-data="crmShell(@js(['activeGroups' => collect($navigation)->where('active', true)->pluck('key')->values()]))">
    @if(request()->boolean('reminder_dismiss_failed'))
        <script>history.replaceState(null, '', new URL(location.href).pathname + new URL(location.href).search.replace(/([?&])reminder_dismiss_failed=1(&|$)/, '$1').replace(/[?&]$/, '') + location.hash)</script>
    @endif

    <header class="fixed inset-x-0 top-0 z-50 flex h-[var(--oasis-topbar-height)] items-center justify-between bg-[var(--oasis-topbar-bg)] px-3 font-[Helvetica] text-sm font-bold text-white sm:px-4">
        <div class="flex min-w-0 items-center gap-2">
            <button type="button" @click="sidebarOpen = !sidebarOpen"
                    class="flex size-11 shrink-0 items-center justify-center hover:bg-white/10 md:hidden"
                    title="Buka navigasi">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
            </button>
            <span class="text-lg text-[var(--oasis-yellow)]" aria-hidden="true">◆</span>
            <a href="{{ route(Auth::user()->landingRouteName()) }}" class="truncate hover:text-gray-300">OASIS CRM</a>
        </div>

        @auth
        <div class="relative flex items-center gap-2"
             x-data="crmNotifications(@js([
                 'indexUrl' => route('notifications.index'),
                 'readUrl' => route('notifications.read', ['notification' => '__ID__']),
                 'readAllUrl' => route('notifications.read-all'),
                 'enabled' => config('notifications.polling_enabled', true),
             ]))" @click.outside="open = false">
            <span class="hidden max-w-40 truncate text-xs sm:block">{{ Auth::user()->name }}</span>
            <button type="button" @click="open = !open; if (open) refresh()"
                    class="relative flex size-11 items-center justify-center hover:bg-white/10">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                </svg>
                <span x-show="unreadCount > 0" x-cloak class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center border border-white bg-[var(--oasis-danger)] px-1 text-[10px] text-white" x-text="unreadCount > 9 ? '9+' : unreadCount"></span>
            </button>
            <div x-show="open" x-cloak x-transition.opacity.duration.150ms
                 class="absolute right-0 top-full z-50 mt-2 max-h-[70vh] w-[340px] max-w-[calc(100vw-1rem)] overflow-y-auto border-2 border-black bg-white text-black shadow-xl">
                <div class="sticky top-0 z-10 flex items-center justify-between bg-black px-3 py-2 text-xs font-bold uppercase text-white">
                    <span>Notifikasi</span>
                    <button type="button" x-show="unreadCount > 0" @click="markAllRead()" class="underline normal-case">Tandai semua dibaca</button>
                </div>
                <div x-show="loading && notifications.length === 0" class="px-3 py-4 text-center text-xs">Memuat notifikasi...</div>
                <div x-show="unavailable" class="border-b border-black bg-[#fff3b0] px-3 py-2 text-xs">Notifikasi sementara tidak tersedia. CRM tetap dapat digunakan.</div>
                <template x-for="notification in notifications" :key="notification.id">
                    <button type="button" @click="markRead(notification, true)" class="block w-full border-b border-black px-3 py-2 text-left hover:bg-[#eef1ff]" :class="notification.read_at ? 'bg-white' : 'bg-[#fff3b0]'">
                        <span class="block text-xs font-bold" x-text="notification.title"></span>
                        <span class="block font-['Times_New_Roman'] text-xs" x-text="notification.message"></span>
                        <span class="mt-1 block text-[10px] text-gray-500" x-text="notification.created_label"></span>
                    </button>
                </template>
                <div x-show="!loading && notifications.length === 0" class="px-3 py-4 text-center text-xs">Belum ada notifikasi kolaborasi.</div>
                <div class="border-y-2 border-black bg-gray-100 px-3 py-2 text-xs font-bold uppercase">Pengingat</div>
                @php($hasAny = $overdueItems->isNotEmpty() || $todayItems->isNotEmpty() || $tomorrowItems->isNotEmpty() || $needsConfirmation->isNotEmpty())
                @if($hasAny)
                    <x-crm.notif-section :items="$overdueItems" color="#c0392b" label="Terlewat" text-color="text-white">
                        @foreach($overdueItems as $item)
                            <div class="px-3 py-2 text-sm"><div class="font-bold">[{{ strtoupper($item->item_type) }}] {{ $item->title }}</div><div class="text-xs text-red-700">Terlewat {{ $item->scheduled_date->diffForHumans() }} - {{ $item->scheduled_date->format('d M Y') }}</div></div>
                        @endforeach
                    </x-crm.notif-section>
                    <x-crm.notif-section :items="$todayItems" color="#f1c40f" label="Hari Ini" text-color="text-black">
                        @foreach($todayItems as $item)
                            <div class="px-3 py-2 text-sm"><div class="font-bold">[{{ strtoupper($item->item_type) }}] {{ $item->title }}</div><div class="text-xs text-gray-600">Jadwal: {{ $item->scheduled_date->format('d M Y') }}</div></div>
                        @endforeach
                    </x-crm.notif-section>
                    <x-crm.notif-section :items="$tomorrowItems" color="#9ab6c8" label="Besok / H-1" text-color="text-black">
                        @foreach($tomorrowItems as $item)
                            <div class="px-3 py-2 text-sm"><div class="font-bold">[{{ strtoupper($item->item_type) }}] {{ $item->title }}</div><div class="text-xs text-gray-600">Besok - {{ $item->scheduled_date->format('d M Y') }}</div></div>
                        @endforeach
                    </x-crm.notif-section>
                    <x-crm.notif-section :items="$needsConfirmation" color="#e6915d" label="Perlu Konfirmasi" text-color="text-black">
                        @foreach($needsConfirmation as $item)
                            <div class="px-3 py-2 text-sm"><div class="font-bold">[Dana] {{ $item->nama_konsumen }} - {{ $item->kav }}</div><div class="text-xs text-orange-700">Butuh konfirmasi keuangan - {{ $item->tanggal->format('d M Y') }}</div></div>
                        @endforeach
                    </x-crm.notif-section>
                @else
                    <div class="px-4 py-6 text-center text-sm">Semua terkendali.</div>
                @endif
            </div>
        </div>
        @endauth
    </header>

    <div x-show="sidebarOpen" @click="sidebarOpen = false" class="fixed inset-0 z-30 bg-black/40 md:hidden" x-cloak></div>

    <div class="crm-shell-frame flex min-h-screen flex-col md:flex-row">
        <aside id="crm-sidebar" :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
               class="crm-sidebar fixed bottom-0 left-0 z-40 flex flex-col border-r-2 border-black shadow-xl transition-transform duration-150 md:shadow-none"
               aria-label="Navigasi utama">
            <nav class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto py-3" aria-label="Area kerja OASIS">
                @foreach($navigation as $group)
                    @if($group['direct'])
                        @php($child = $group['children'][0])
                        <a href="{{ route($child['route']) }}" class="crm-nav-item {{ $child['active'] ? 'is-active' : '' }}"
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
                                    <a href="{{ route($child['route']) }}" class="crm-nav-item {{ $child['active'] ? 'is-active' : '' }}"
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
                <div class="flex items-center justify-between gap-2 md:hidden">
                    <a href="{{ route('profile.edit') }}" class="px-3 font-[Helvetica] text-xs font-bold underline">Akun</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="min-h-11 px-3 font-[Helvetica] text-xs font-bold text-[var(--oasis-danger)]">Logout</button></form>
                </div>
                <button type="button" @click="toggleDesktopSidebar()"
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
                     :class="{ 'bg-[#b3bd95]': toast.type === 'success', 'bg-[#d77a7a]': toast.type === 'error', 'bg-[#fcc20f]': toast.type === 'warning' }"
                     :role="toast.type === 'error' ? 'alert' : 'status'"
                     class="flex min-w-[250px] items-start justify-between gap-3 border-2 border-black px-3 py-2 text-xs shadow-xl pointer-events-auto">
                    <span x-text="toast.message"></span>
                    <button type="button" @click="dismiss(toast.id)" class="shrink-0 text-lg font-bold leading-none text-black/60 hover:text-black" aria-label="Tutup notifikasi">&times;</button>
                </div>
            </template>
        </div>

        <main id="crm-main" class="crm-main flex-1 overflow-x-hidden p-4 sm:p-6">
            @yield('content')
            <footer class="mt-6 border-t-2 border-black bg-white px-4 py-3 text-center text-xs">&copy; {{ date('Y') }} Oasis CRM</footer>
        </main>
    </div>

    <x-conflict-dialog :initial="session('conflict_data')" />
    @unless($isSalesNavigation)
        @include('crm.ai-chat._widget')
    @endunless
    <x-crm.feedback-bubble />
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('crmDetailModal', function (fetchBase, editBase, statusColors) {
                return {
                    open: false,
                    loading: false,
                    task: null,
                    sc: statusColors || {},
                    openDetail: function (id) {
                        this.open = true;
                        this.loading = true;
                        this.task = null;
                        var self = this;
                        fetch(fetchBase + '/' + id + '/detail')
                            .then(function (response) { return response.json(); })
                            .then(function (data) { self.task = data; self.loading = false; })
                            .catch(function () { self.loading = false; alert('Gagal memuat detail.'); });
                    },
                    close: function () { this.open = false; this.loading = false; this.task = null; },
                    get editUrl() { return this.task ? editBase + '/' + this.task.id + '/edit' : '#'; }
                };
            });
        });
    </script>
    @stack('scripts')
</body>
</html>
