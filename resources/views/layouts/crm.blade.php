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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        @media (max-width: 767px) {
            button, select, input, textarea, a, [class*="py-1"] {
                min-height: 44px !important;
                font-size: 16px !important;
            }
            table th, table td {
                padding: 6px 4px !important;
                font-size: 12px !important;
            }
            .filter-bar {
                flex-direction: column !important;
                gap: 0.5rem !important;
            }
            .filter-bar select, .filter-bar input {
                width: 100% !important;
            }
        }
        @media (min-width: 768px) and (max-width: 1024px) {
            .filter-bar {
                flex-direction: column !important;
                gap: 0.5rem !important;
            }
            .filter-bar select, .filter-bar input {
                width: 100% !important;
            }
            table th, table td {
                padding: 5px 4px !important;
                font-size: 12px !important;
            }
        }
    </style>
</head>
<body class="font-['Times_New_Roman'] antialiased bg-white min-h-screen flex flex-col"
      x-data="{ sidebarOpen: localStorage.getItem('sidebarOpen') !== 'false', sidebarPinned: localStorage.getItem('sidebarPinned') === 'true', bellOpen: false }">
    {{-- Header --}}
    <div class="fixed top-0 left-0 right-0 z-50 flex-shrink-0 bg-black text-white font-[Helvetica] font-bold text-sm sm:text-base px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <button @click="sidebarOpen = !sidebarOpen; localStorage.setItem('sidebarOpen', sidebarOpen)"
                    class="sm:hidden hover:text-gray-300 text-base leading-none" title="Toggle sidebar">
                <span x-show="sidebarOpen">◀</span>
                <span x-show="!sidebarOpen">▶</span>
            </button>
            <span class="text-[#fcc20f] text-lg">◆</span>
            <a href="{{ route('dashboard') }}" class="hover:text-gray-300">OASIS CRM</a>
        </div>
        @auth
        <div class="relative flex items-center gap-2" @click.outside="bellOpen = false">
            <span class="text-xs">{{ Auth::user()->name }}</span>
            <button @click="bellOpen = !bellOpen"
                    class="relative flex items-center justify-center w-8 h-8 hover:bg-white/10 rounded-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                </svg>
                @if($totalCount > 0)
                <span class="absolute -top-1 -right-1 bg-[#c0392b] text-white text-[10px] font-[Helvetica] font-bold min-w-[16px] h-4 flex items-center justify-center border border-white rounded-none px-1">{{ $totalCount > 9 ? '9+' : $totalCount }}</span>
                @endif
            </button>
            <div x-show="bellOpen" x-cloak
                 x-transition.opacity.duration.150ms
                 class="absolute right-0 top-full mt-2 bg-white border-2 border-black text-black min-w-[280px] max-h-80 overflow-y-auto z-50 shadow-xl">
                <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase sticky top-0">Perhatian</div>
                @php $hasAny = $overdueItems->count() > 0 || $todayItems->count() > 0 || $needsConfirmation->count() > 0; @endphp
                @if($hasAny)
                <x-crm.notif-section :items="$overdueItems" color="#c0392b" label="Terlewat" text-color="text-white">
                    @foreach($overdueItems as $item)
                    <div class="px-3 py-2 text-sm font-['Times_New_Roman']">
                        <div class="font-bold">{{ $item->title }}</div>
                        <div class="text-xs text-red-700">Terlewat {{ $item->scheduled_date->diffForHumans() }} — {{ $item->scheduled_date->format('d M Y') }}</div>
                    </div>
                    @endforeach
                </x-crm.notif-section>
                <x-crm.notif-section :items="$todayItems" color="#f1c40f" label="Hari Ini" text-color="text-black">
                    @foreach($todayItems as $item)
                    <div class="px-3 py-2 text-sm font-['Times_New_Roman']">
                        <div class="font-bold">{{ $item->title }}</div>
                        <div class="text-xs text-gray-600">Jadwal: {{ $item->scheduled_date->format('d M Y') }}</div>
                    </div>
                    @endforeach
                </x-crm.notif-section>
                <x-crm.notif-section :items="$needsConfirmation" color="#e6915d" label="Perlu Konfirmasi" text-color="text-black">
                    @foreach($needsConfirmation as $item)
                    <div class="px-3 py-2 text-sm font-['Times_New_Roman']">
                        <div class="font-bold">[Dana] {{ $item->nama_konsumen }} — {{ $item->kav }}</div>
                        <div class="text-xs text-orange-700">Butuh konfirmasi keuangan — {{ $item->tanggal->format('d M Y') }}</div>
                    </div>
                    @endforeach
                </x-crm.notif-section>

                @else
                <div class="px-4 py-6 text-center text-sm font-['Times_New_Roman']">Semua terkendali.</div>
                @endif
            </div>
        </div>
        @endauth
    </div>

    {{-- Backdrop overlay for mobile sidebar --}}
    <div x-show="sidebarOpen" @click="sidebarOpen = false"
         class="fixed inset-0 bg-black/40 z-30 sm:hidden" x-cloak></div>

    {{-- Body --}}
    <div class="flex flex-col sm:flex-row pt-14">
        <div :class="[sidebarOpen ? 'block' : 'hidden', sidebarPinned ? 'sm:w-56' : 'sm:w-16 sm:hover:w-56']"
             class="sm:block group w-64 sm:w-16 flex flex-col shadow-xl
                    fixed left-0 top-14 bottom-0 z-40
                    sm:fixed sm:left-0 sm:top-14 sm:bottom-0 sm:z-40
                    sm:transition-all sm:duration-200
                     sm:overflow-x-hidden sm:whitespace-nowrap sm:shadow-none
                    bg-white sm:border-r-2 border-black">
            <nav :class="sidebarPinned ? 'sm:overflow-y-auto' : 'sm:overflow-y-hidden sm:group-hover:overflow-y-auto'" class="overflow-x-hidden overflow-y-auto flex-1 min-h-0 p-2">
                <div class="mb-1 hidden sm:block">
                    <button @click="sidebarPinned = !sidebarPinned; localStorage.setItem('sidebarPinned', sidebarPinned)"
                            :class="sidebarPinned ? 'bg-green-50 text-green-700 border-green-500 hover:bg-green-100' : 'text-gray-500 border-gray-300 hover:text-black hover:bg-gray-100'"
                            class="w-full flex items-center px-2 py-1.5 gap-2 text-[10px] font-[Helvetica] font-bold border rounded-none transition-colors duration-200"
                            :title="sidebarPinned ? 'Lepas pin sidebar' : 'Pin sidebar'">
                        <span x-text="sidebarPinned ? '📍' : '📌'" class="text-xs">📌</span>
                        <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'"
                              class="group-hover:sm:w-auto transition-all duration-200 delay-150"
                              x-text="sidebarPinned ? 'Unpin' : 'Pin'">Pin</span>
                    </button>
                </div>
                <div class="border-t border-dashed border-gray-400 mx-2 my-2"></div>
                <div class="px-2 mb-0.5 text-[9px] font-[Helvetica] font-bold text-gray-400 uppercase tracking-[0.15em]">
                    <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">General</span>
                </div>
                <a href="{{ route('dashboard') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#9ab6c8] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('dashboard') ? 'bg-[#9ab6c8]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#9ab6c8]' : 'text-black group-hover:text-[#9ab6c8]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Dashboard</span>
                </a>
                <a href="{{ route('content-calendar.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#b3bd95] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('content-calendar.*') ? 'bg-[#b3bd95]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#b3bd95]' : 'text-black group-hover:text-[#b3bd95]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Task Tracker</span>
                </a>
                <a href="{{ route('database.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#d77a7a] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('database.*') ? 'bg-[#d77a7a]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#d77a7a]' : 'text-black group-hover:text-[#d77a7a]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Database</span>
                </a>
                <a href="{{ route('konsumen-progress.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#5d8e8e] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('konsumen-progress.*') ? 'bg-[#5d8e8e]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#5d8e8e]' : 'text-black group-hover:text-[#5d8e8e]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Konsumen Progress</span>
                </a>
                <div class="border-t border-dashed border-gray-400 mx-2 my-2"></div>
                <div class="px-2 mb-0.5 text-[9px] font-[Helvetica] font-bold text-gray-400 uppercase tracking-[0.15em]">
                    <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Leads</span>
                </div>
                <a href="{{ route('leads.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#e6915d] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('leads.*') ? 'bg-[#e6915d]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#e6915d]' : 'text-black group-hover:text-[#e6915d]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Leads</span>
                </a>
                <div class="border-t border-dashed border-gray-400 mx-2 my-2"></div>
                <div class="px-2 mb-0.5 text-[9px] font-[Helvetica] font-bold text-gray-400 uppercase tracking-[0.15em]">
                    <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Laporan</span>
                </div>
                <a href="{{ route('dana-talangan.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#f1c40f] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('dana-talangan.*') ? 'bg-[#f1c40f]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#f1c40f]' : 'text-black group-hover:text-[#f1c40f]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Dana Talangan</span>
                </a>
                @if(Auth::user() && Auth::user()->isSuperadmin())
                <div class="border-t border-dashed border-gray-400 mx-2 my-2"></div>
                <div class="px-2 mb-0.5 text-[9px] font-[Helvetica] font-bold text-gray-400 uppercase tracking-[0.15em]">
                    <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Pengaturan</span>
                </div>
                <a href="{{ route('branches.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#e6915d] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('branches.*') ? 'bg-[#e6915d]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#e6915d]' : 'text-black group-hover:text-[#e6915d]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Cabang</span>
                </a>
                <a href="{{ route('projects.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#5d8e8e] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('projects.*') ? 'bg-[#5d8e8e]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#5d8e8e]' : 'text-black group-hover:text-[#5d8e8e]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75a4.5 4.5 0 0 1-4.884 4.484c-1.076-.091-2.264.071-2.95.904l-7.152 8.684a2.548 2.548 0 1 1-3.586-3.586l8.684-7.152c.833-.686.995-1.874.904-2.95a4.5 4.5 0 0 1 6.336-4.486l-3.276 3.276a3.004 3.004 0 0 0 2.25 2.25l3.276-3.276c.256.565.398 1.192.398 1.852Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.867 19.125h.008v.008h-.008v-.008Z"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Proyek</span>
                </a>
                <a href="{{ route('admin-users.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#8c9ae0] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('admin-users.*') ? 'bg-[#8c9ae0]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" :class="sidebarPinned ? 'text-[#8c9ae0]' : 'text-black group-hover:text-[#8c9ae0]'" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg> <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">User</span>
                </a>
                @endif
                <div class="border-t border-dashed border-gray-400 mx-2 my-2"></div>
                <a href="{{ route('changelogs.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-gray-500 hover:text-black hover:bg-gray-100 border border-gray-300 hover:border-black mb-1 mx-2 rounded-none {{ request()->routeIs('changelogs.*') ? 'bg-gray-100 text-black border-black' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'" class="group-hover:sm:w-auto transition-all duration-200 delay-150">Changelog</span>
                </a>
            </nav>
            @auth
            <div class="border-t-2 border-black p-2 flex-shrink-0 flex-grow bg-[#c0392b]">
                <div class="flex items-center justify-between gap-2 px-2 py-1">
                    <span :class="sidebarPinned ? '' : 'sm:w-0 sm:overflow-hidden sm:whitespace-nowrap'"
                          class="text-xs font-[Helvetica] font-bold text-[#fcc20f] group-hover:sm:w-auto transition-all duration-200 delay-150">
                        {{ Auth::user()->name }}
                    </span>
                    <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('Apakah Anda yakin ingin logout?')">
                        @csrf
                        <button type="submit" class="flex items-center justify-center w-7 h-7 text-sm bg-[#c0392b] hover:bg-[#a93226] text-white border-2 border-white shadow-[inset_0_-2px_0_0_rgba(0,0,0,0.3)] rounded-none shrink-0"
                                title="Logout">
                            <span>⏻</span>
                        </button>
                    </form>
                </div>
            </div>
            @endauth
        </div>

        {{-- Floating toast notifications --}}
        <div class="fixed top-16 right-4 z-[999] flex flex-col gap-2 max-w-sm pointer-events-none">
            @if(session('success'))
            <div x-data="{ show: true }"
                 x-init="setTimeout(() => show = false, 4000)"
                 x-show="show"
                 x-transition:leave="transition ease-out duration-500"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 translate-x-4"
                 class="bg-[#b3bd95] border-2 border-black px-3 py-2 font-['Times_New_Roman'] text-xs shadow-xl flex items-start justify-between gap-3 min-w-[250px] pointer-events-auto">
                <span>{{ session('success') }}</span>
                <button @click="show = false" class="text-black/60 hover:text-black font-bold text-lg leading-none shrink-0">&times;</button>
            </div>
            @endif
            @if(session('error'))
            <div x-data="{ show: true }"
                 x-init="setTimeout(() => show = false, 4000)"
                 x-show="show"
                 x-transition:leave="transition ease-out duration-500"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 translate-x-4"
                 class="bg-[#d77a7a] border-2 border-black px-3 py-2 font-['Times_New_Roman'] text-xs shadow-xl flex items-start justify-between gap-3 min-w-[250px] pointer-events-auto">
                <span>{{ session('error') }}</span>
                <button @click="show = false" class="text-black/60 hover:text-black font-bold text-lg leading-none shrink-0">&times;</button>
            </div>
            @endif
        </div>

        <div :class="sidebarPinned ? 'sm:ml-56' : 'sm:ml-16'" class="flex-1 min-w-0 max-w-full overflow-x-hidden p-4 sm:p-6 sm:ml-16">
            @yield('content')

            <div class="border-t-2 border-black bg-white px-4 py-3 text-center text-xs font-['Times_New_Roman'] mt-6">
                © {{ date('Y') }} Oasis CRM
            </div>
        </div>
    </div>
<div x-data="{
    open: false,
    tab: 'notifikasi',
    previousTab: 'notifikasi',
    reports: [],
    pendingCount: 0,
    isSuperadmin: false,
    loading: false,
    adminNotes: {},
    selectedReport: null,
    historyReports: [],
    historyNextUrl: null,
    hasMoreHistory: false,
    loadingHistory: false,
    type: 'masukan',
    title: '',
    description: '',
    sending: false,
    sent: false,

    fetchReports() {
        this.loading = true;
        fetch('{{ route('feedback-reports.fetch-recent') }}')
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    this.reports = d.reports;
                    this.pendingCount = d.pending_count;
                    this.isSuperadmin = d.is_superadmin;
                }
            })
            .finally(() => { this.loading = false; });
    },

    fetchHistory() {
        this.loadingHistory = true;
        fetch('{{ route('feedback-reports.fetch-history') }}')
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    this.historyReports = d.reports;
                    this.historyNextUrl = d.next_page_url;
                    this.hasMoreHistory = d.has_more;
                }
            })
            .finally(() => { this.loadingHistory = false; });
    },

    loadMore() {
        if (!this.historyNextUrl || this.loadingHistory) return;
        this.loadingHistory = true;
        fetch(this.historyNextUrl)
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    this.historyReports = this.historyReports.concat(d.reports);
                    this.historyNextUrl = d.next_page_url;
                    this.hasMoreHistory = d.has_more;
                }
            })
            .finally(() => { this.loadingHistory = false; });
    },

    showDetail(r) {
        this.previousTab = this.tab;
        this.selectedReport = r;
        this.tab = 'detail';
    },

    backFromDetail() {
        this.tab = this.previousTab;
        this.selectedReport = null;
    },

    doAction(id, action) {
        const note = this.adminNotes[id] || '';
        fetch('feedback-reports/' + id + '/' + action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ admin_note: note })
        })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                delete this.adminNotes[id];
                this.fetchReports();
                this.fetchHistory();
                if (this.selectedReport && this.selectedReport.id === id) {
                    this.selectedReport.status = action === 'approve' ? 'approved'
                        : action === 'reject' ? 'rejected'
                        : action === 'implement' ? 'implemented'
                        : 'fixed';
                    this.selectedReport.admin_note = this.adminNotes[id] || this.selectedReport.admin_note;
                }
            } else {
                alert(d.error || 'Gagal');
            }
        })
        .catch(e => alert('Gagal: ' + e.message));
    },

    statusLabel(status) {
        const map = { pending: 'PENDING', approved: 'DISETUJUI', rejected: 'DITOLAK', implemented: 'IMPLEMENTASI', fixed: 'FIXED' };
        return map[status] || status;
    },

    typeStyle(type) {
        return { backgroundColor: type === 'bug' ? '#d77a7a' : '#e6915d', color: '#fff' };
    },

    statusStyle(status) {
        const map = {
            pending: { backgroundColor: '#f1c40f', color: '#000' },
            approved: { backgroundColor: '#27ae60', color: '#fff' },
            rejected: { backgroundColor: '#c0392b', color: '#fff' },
            implemented: { backgroundColor: '#2980b9', color: '#fff' },
            fixed: { backgroundColor: '#7f8c8d', color: '#fff' },
        };
        return map[status] || { backgroundColor: '#ccc', color: '#000' };
    }
}" class="fixed bottom-4 right-4 z-50" x-cloak x-init="fetchReports()">
    <template x-if="!open">
        <button @click="open = true; sent = false; fetchReports(); fetchHistory()"
                class="relative w-12 h-12 bg-[#c0392b] hover:bg-[#a93226] text-white border-2 border-black rounded-none flex items-center justify-center shadow-lg transition-colors duration-200"
                title="Laporan / Masukan">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z"/>
            </svg>
            <span x-show="pendingCount > 0"
                  class="absolute -top-2 -right-2 bg-[#c0392b] text-white text-[10px] font-[Helvetica] font-bold min-w-[18px] h-[18px] flex items-center justify-center border-2 border-white rounded-none px-1 shadow-md"
                  x-text="pendingCount > 9 ? '9+' : pendingCount"></span>
        </button>
    </template>
    <template x-if="open">
        <div class="bg-white border-2 border-black shadow-xl w-80 font-['Times_New_Roman'] max-h-[80vh] flex flex-col">
            {{-- Header --}}
            <div class="bg-[#c0392b] text-white px-3 py-2 flex items-center justify-between text-sm font-bold font-[Helvetica] shrink-0">
                <template x-if="tab !== 'detail'">
                    <span>Laporan / Masukan</span>
                </template>
                <template x-if="tab === 'detail'">
                    <button @click="backFromDetail()" class="flex items-center gap-1 hover:text-gray-300 text-xs font-[Helvetica] font-bold">
                        ← Kembali
                    </button>
                </template>
                <button @click="open = false" class="hover:text-gray-300 text-lg leading-none">&times;</button>
            </div>

            {{-- Tab nav (hidden in detail view) --}}
            <template x-if="tab !== 'detail'">
            <div class="flex border-b-2 border-black shrink-0">
                <button @click="tab = 'notifikasi'; fetchReports()"
                        :class="tab === 'notifikasi' ? 'bg-black text-white' : 'bg-gray-100 text-black hover:bg-gray-200'"
                        class="flex-1 px-3 py-2 text-xs font-[Helvetica] font-bold border-r-2 border-black transition-colors duration-150">
                    <span x-text="'📋 Notifikasi (' + pendingCount + ')'"></span>
                </button>
                <button @click="tab = 'history'; fetchHistory()"
                        :class="tab === 'history' ? 'bg-black text-white' : 'bg-gray-100 text-black hover:bg-gray-200'"
                        class="flex-1 px-3 py-2 text-xs font-[Helvetica] font-bold border-r-2 border-black transition-colors duration-150">
                    📜 History
                </button>
                <button @click="tab = 'kirim'"
                        :class="tab === 'kirim' ? 'bg-black text-white' : 'bg-gray-100 text-black hover:bg-gray-200'"
                        class="flex-1 px-3 py-2 text-xs font-[Helvetica] font-bold transition-colors duration-150">
                    ✏️ Kirim
                </button>
            </div>
            </template>

            <div class="overflow-y-auto flex-1 min-h-0">
                {{-- Notifikasi tab --}}
                <template x-if="tab === 'notifikasi'">
                    <div>
                        <template x-if="loading">
                            <div class="p-6 text-center text-sm text-gray-500">Memuat...</div>
                        </template>
                        <template x-if="!loading && reports.length === 0">
                            <div class="p-6 text-center text-sm text-gray-500">Belum ada laporan.</div>
                        </template>
                        <template x-if="!loading && reports.length > 0">
                            <div>
                                <template x-for="r in reports" :key="r.id">
                                    <div @click="showDetail(r)"
                                         class="px-3 py-2.5 border-b border-gray-200 hover:bg-gray-100 cursor-pointer">
                                        <div class="flex items-start justify-between gap-2 mb-1">
                                            <span class="text-sm font-bold truncate max-w-[180px]" x-text="r.title" :title="r.description"></span>
                                            <span class="inline-block px-1.5 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black shrink-0"
                                                   :style="typeStyle(r.type)"
                                                   x-text="r.type === 'bug' ? 'BUG' : 'MASUKAN'"></span>
                                        </div>
                                        <div class="flex items-center gap-2 text-[11px] text-gray-600 mb-1.5">
                                            <span x-text="r.creator_name"></span>
                                            <span x-text="'• ' + r.branch_name"></span>
                                            <span x-text="r.created_at" class="ml-auto text-gray-400"></span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block px-1.5 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black"
                                                  :style="statusStyle(r.status)"
                                                  x-text="statusLabel(r.status)"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- History tab --}}
                <template x-if="tab === 'history'">
                    <div>
                        <template x-if="loadingHistory && historyReports.length === 0">
                            <div class="p-6 text-center text-sm text-gray-500">Memuat...</div>
                        </template>
                        <template x-if="!loadingHistory && historyReports.length === 0">
                            <div class="p-6 text-center text-sm text-gray-500">Belum ada riwayat.</div>
                        </template>
                        <template x-if="historyReports.length > 0">
                            <div>
                                <template x-for="r in historyReports" :key="r.id">
                                    <div @click="showDetail(r)"
                                         class="px-3 py-2.5 border-b border-gray-200 hover:bg-gray-100 cursor-pointer">
                                        <div class="flex items-start justify-between gap-2 mb-1">
                                            <span class="text-sm font-bold truncate max-w-[180px]" x-text="r.title"></span>
                                            <span class="inline-block px-1.5 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black shrink-0"
                                                  :style="typeStyle(r.type)"
                                                  x-text="r.type === 'bug' ? 'BUG' : 'MASUKAN'"></span>
                                        </div>
                                        <div class="flex items-center gap-2 text-[11px] text-gray-600 mb-1.5">
                                            <span x-text="r.creator_name"></span>
                                            <span x-text="'• ' + r.branch_name"></span>
                                            <span x-text="r.created_at" class="ml-auto text-gray-400"></span>
                                        </div>
                                        <div>
                                            <span class="inline-block px-1.5 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black"
                                                  :style="statusStyle(r.status)"
                                                  x-text="statusLabel(r.status)"></span>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="hasMoreHistory">
                                    <div class="p-3 text-center">
                                        <button @click="loadMore()" :disabled="loadingHistory"
                                                class="text-xs font-[Helvetica] font-bold underline hover:text-[#c0392b] disabled:opacity-40"
                                                x-text="loadingHistory ? 'Memuat...' : 'Muat lebih'"></button>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Detail view --}}
                <template x-if="tab === 'detail' && selectedReport">
                    <div class="p-3">
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <span class="text-sm font-bold" x-text="selectedReport.title"></span>
                            <span class="inline-block px-1.5 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black shrink-0"
                                  :style="typeStyle(selectedReport.type)"
                                  x-text="selectedReport.type === 'bug' ? 'BUG' : 'MASUKAN'"></span>
                        </div>

                        <div class="text-[11px] text-gray-600 mb-2 space-y-0.5">
                            <div><span class="font-bold">Pelapor:</span> <span x-text="selectedReport.creator_name"></span></div>
                            <div><span class="font-bold">Cabang:</span> <span x-text="selectedReport.branch_name"></span></div>
                            <div><span class="font-bold">Tanggal:</span> <span x-text="selectedReport.created_at"></span></div>
                        </div>

                        <div class="mb-2">
                            <span class="inline-block px-1.5 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black"
                                  :style="statusStyle(selectedReport.status)"
                                  x-text="statusLabel(selectedReport.status)"></span>
                        </div>

                        <div class="bg-gray-50 border border-black p-2 mb-3 text-sm whitespace-pre-line break-words max-h-32 overflow-y-auto">
                            <span x-text="(selectedReport.description || '').trim() || '(Tidak ada deskripsi)'"></span>
                        </div>

                        <template x-if="selectedReport.admin_note">
                            <div class="mb-3">
                                <div class="text-[10px] font-[Helvetica] font-bold uppercase text-gray-500 mb-0.5">Catatan Admin:</div>
                                <div class="bg-yellow-50 border border-black p-2 text-sm whitespace-pre-line break-words" x-text="(selectedReport.admin_note || '').trim()"></div>
                            </div>
                        </template>

                        <template x-if="isSuperadmin && selectedReport.status === 'pending'">
                            <div class="border-t-2 border-black pt-3">
                                <input x-model="adminNotes[selectedReport.id]"
                                       class="w-full border-2 border-black px-2 py-1.5 text-sm mb-2"
                                       placeholder="Catatan (opsional)...">
                                <div class="flex gap-2">
                                    <button @click="doAction(selectedReport.id, 'approve')"
                                            style="background-color:#27ae60;color:#fff;"
                                            class="flex-1 border-2 border-black px-3 py-1.5 text-sm font-bold font-[Helvetica]">
                                        Setuju
                                    </button>
                                    <button @click="doAction(selectedReport.id, 'reject')"
                                            style="background-color:#c0392b;color:#fff;"
                                            class="flex-1 border-2 border-black px-3 py-1.5 text-sm font-bold font-[Helvetica]">
                                        Tolak
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template x-if="isSuperadmin && selectedReport.status === 'approved'">
                            <div class="border-t-2 border-black pt-3">
                                <div class="flex gap-2">
                                    <button @click="doAction(selectedReport.id, 'implement')"
                                            style="background-color:#2980b9;color:#fff;"
                                            class="flex-1 border-2 border-black px-3 py-1.5 text-sm font-bold font-[Helvetica]">
                                        Implementasi
                                    </button>
                                    <button @click="doAction(selectedReport.id, 'fix')"
                                            style="background-color:#7f8c8d;color:#fff;"
                                            class="flex-1 border-2 border-black px-3 py-1.5 text-sm font-bold font-[Helvetica]">
                                        Fixed
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Kirim tab --}}
                <template x-if="tab === 'kirim'">
                    <div class="p-3">
                        <template x-if="sent">
                            <div class="text-center py-4 text-sm text-green-700 font-bold border-2 border-green-700 bg-green-50 px-3 py-2">
                                Terkirim! Terima kasih atas laporannya.
                                <button @click="sent = false; type = 'masukan'; title = ''; description = ''" class="block mx-auto mt-2 text-xs text-gray-500 underline">Kirim lagi</button>
                            </div>
                        </template>
                        <template x-if="!sent">
                            <form @submit.prevent="sending = true; fetch('{{ route('feedback-reports.store') }}', { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ type: type, title: title, description: description }) }).then(async r => { const text = await r.text(); try { return JSON.parse(text); } catch { return { ok: false, error: text.substring(0,200) }; } }).then(d => { sending = false; if (d.ok) { sent = true; type = 'masukan'; title = ''; description = ''; fetchReports(); } else { alert('Gagal: ' + (d.error || d.message || 'Coba lagi.')); } }).catch(e => { sending = false; alert('Gagal: ' + e.message); })">
                                <div class="flex gap-2 mb-2">
                                    <label class="flex items-center gap-1 text-xs cursor-pointer">
                                        <input type="radio" x-model="type" value="masukan" class="cursor-pointer">
                                        <span class="inline-block px-1.5 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black bg-[#e6915d] text-white">MASUKAN</span>
                                    </label>
                                    <label class="flex items-center gap-1 text-xs cursor-pointer">
                                        <input type="radio" x-model="type" value="bug" class="cursor-pointer">
                                        <span class="inline-block px-1.5 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black bg-[#d77a7a] text-white">BUG</span>
                                    </label>
                                </div>
                                <input x-model.trim="title" required maxlength="255"
                                       class="w-full border-2 border-black px-2 py-1.5 text-sm mb-2"
                                       placeholder="Judul singkat...">
                                <textarea x-model.trim="description" required maxlength="5000" rows="3"
                                          class="w-full border-2 border-black px-2 py-1.5 text-sm resize-none focus:outline-none"
                                          placeholder="Jelaskan detailnya..."></textarea>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-xs text-gray-500" x-text="description.length + '/5000'"></span>
                                    <button type="submit" :disabled="sending || !title.trim() || !description.trim()"
                                            class="bg-[#c0392b] hover:bg-[#a93226] text-white border-2 border-black px-4 py-1 text-sm font-bold font-[Helvetica] disabled:opacity-40 transition-colors duration-200"
                                            x-text="sending ? 'Mengirim...' : 'Kirim'"></button>
                                </div>
                            </form>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
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
                    .then(function (r) { return r.json(); })
                    .then(function (data) { self.task = data; self.loading = false; })
                    .catch(function () { self.loading = false; alert('Gagal memuat detail.'); });
            },
            close: function () {
                this.open = false;
                this.loading = false;
                this.task = null;
            },
            get editUrl() {
                return this.task ? editBase + '/' + this.task.id + '/edit' : '#';
            }
        };
    });
});
</script>
@stack('scripts')
</body>
</html>
