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
</head>
<body class="font-['Times_New_Roman'] antialiased bg-white min-h-screen flex flex-col"
      x-data="{ sidebarOpen: localStorage.getItem('sidebarOpen') !== 'false' }">
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
        <div x-data="{ profileOpen: false }" class="relative">
            <button @click="profileOpen = !profileOpen"
                    class="flex items-center gap-1.5 text-xs bg-[#c0392b] hover:bg-[#a93226] px-2 py-1.5 border border-white/30 rounded-none">
                <span>{{ Auth::user()->name }}</span>
                <span x-show="!profileOpen">▼</span>
                <span x-show="profileOpen">▲</span>
            </button>
            <div x-show="profileOpen" @click.outside="profileOpen = false"
                 x-transition.opacity.duration.150ms
                 class="absolute right-0 top-full mt-1 bg-white border-2 border-black text-black min-w-[140px] z-50">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full px-3 py-2 text-xs font-[Helvetica] font-bold hover:bg-[#d77a7a] text-left flex items-center gap-2">
                        <span>⏻</span> Logout
                    </button>
                </form>
            </div>
        </div>
        @endauth
    </div>

    {{-- Body --}}
    <div class="flex flex-col sm:flex-row flex-1 pt-14">
        <div :class="sidebarOpen ? 'block' : 'hidden'"
             class="sm:block group w-full sm:w-12 sm:hover:w-56 sm:transition-all sm:duration-200 sm:overflow-x-hidden sm:whitespace-nowrap sm:sticky sm:top-14 sm:max-h-[calc(100vh-3.5rem)] sm:self-start sm:overflow-y-auto bg-white border-b-2 sm:border-b-0 sm:border-r-2 border-black">
            <nav class="p-2">
                <a href="{{ route('dashboard') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#9ab6c8] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('dashboard') ? 'bg-[#9ab6c8]' : 'bg-white' }}">
                    <span class="text-[#9ab6c8]">█</span> <span class="sm:opacity-0 sm:group-hover:opacity-100 sm:transition-opacity sm:duration-200 sm:delay-150">Dashboard</span>
                </a>
                <a href="{{ route('content-calendar.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#b3bd95] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('content-calendar.*') ? 'bg-[#b3bd95]' : 'bg-white' }}">
                    <span class="text-[#b3bd95]">█</span> <span class="sm:opacity-0 sm:group-hover:opacity-100 sm:transition-opacity sm:duration-200 sm:delay-150">Content Calendar</span>
                </a>
                <a href="{{ route('database.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#d77a7a] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('database.*') ? 'bg-[#d77a7a]' : 'bg-white' }}">
                    <span class="text-[#d77a7a]">█</span> <span class="sm:opacity-0 sm:group-hover:opacity-100 sm:transition-opacity sm:duration-200 sm:delay-150">Database</span>
                </a>
                @if(Auth::user() && Auth::user()->isSuperadmin())
                <div class="border-t border-dashed border-gray-400 mx-2 my-2 pt-1 text-[10px] text-gray-500 text-center tracking-[0.2em]">── Pengaturan ──</div>
                <a href="{{ route('branches.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#e6915d] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('branches.*') ? 'bg-[#e6915d]' : 'bg-white' }}">
                    <span class="text-[#e6915d]">█</span> <span class="sm:opacity-0 sm:group-hover:opacity-100 sm:transition-opacity sm:duration-200 sm:delay-150">Cabang</span>
                </a>
                <a href="{{ route('admin-users.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#8c9ae0] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('admin-users.*') ? 'bg-[#8c9ae0]' : 'bg-white' }}">
                    <span class="text-[#8c9ae0]">█</span> <span class="sm:opacity-0 sm:group-hover:opacity-100 sm:transition-opacity sm:duration-200 sm:delay-150">User</span>
                </a>
                @endif
            </nav>
        </div>

        <div class="flex-1 p-4 sm:p-6">
            @if(session('success'))
                <div class="bg-[#b3bd95] border-2 border-black px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="bg-[#d77a7a] border-2 border-black px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm">
                    {{ session('error') }}
                </div>
            @endif
            @yield('content')

            <div class="border-t-2 border-black bg-white px-4 py-3 text-center text-xs font-['Times_New_Roman'] mt-6">
                © {{ date('Y') }} Oasis CRM — Sistem Manajemen Konten Perumahan
            </div>
        </div>
    </div>
</body>
</html>
