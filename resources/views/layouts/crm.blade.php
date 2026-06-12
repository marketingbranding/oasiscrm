<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Oasis CRM')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-['Times_New_Roman'] antialiased bg-white min-h-screen flex flex-col"
      x-data="{ sidebarOpen: localStorage.getItem('sidebarOpen') !== 'false' }">
    <div class="flex flex-col flex-1">
        {{-- Header --}}
        <div class="bg-black text-white font-[Helvetica] font-bold text-sm sm:text-base px-4 py-3 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2">
                <button @click="sidebarOpen = !sidebarOpen; localStorage.setItem('sidebarOpen', sidebarOpen)"
                        class="hover:text-gray-300 text-base leading-none" title="Toggle sidebar">
                    <span x-show="sidebarOpen">◀</span>
                    <span x-show="!sidebarOpen">▶</span>
                </button>
                <span class="text-[#fcc20f] text-lg">◆</span>
                <span>OASIS CRM</span>
            </div>
            <div class="flex items-center gap-4">
                @auth
                    <span class="font-['Times_New_Roman'] font-normal text-xs">{{ Auth::user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="bg-[#fcc20f] text-black px-3 py-1 text-xs font-[Helvetica] font-bold border border-black rounded-none hover:bg-yellow-400">
                            Logout
                        </button>
                    </form>
                @endauth
            </div>
        </div>

        {{-- Body --}}
        <div class="flex flex-col sm:flex-row flex-1 min-h-0">
            <div x-show="sidebarOpen" x-transition.opacity.duration.200ms
                 class="w-full sm:w-48 bg-white border-b-2 sm:border-b-0 sm:border-r-2 border-black overflow-y-auto">
                <nav class="p-2">
                    <a href="{{ route('dashboard') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#9ab6c8] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('dashboard') ? 'bg-[#9ab6c8]' : 'bg-white' }}">
                        <span class="text-[#9ab6c8]">█</span> Dashboard
                    </a>
                    <a href="{{ route('content-calendar.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#b3bd95] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('content-calendar.*') ? 'bg-[#b3bd95]' : 'bg-white' }}">
                        <span class="text-[#b3bd95]">█</span> Content Calendar
                    </a>
                    <a href="{{ route('database.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#d77a7a] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('database.*') ? 'bg-[#d77a7a]' : 'bg-white' }}">
                        <span class="text-[#d77a7a]">█</span> Database
                    </a>
                    @if(Auth::user() && Auth::user()->isSuperadmin())
                    <a href="{{ route('branches.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#e6915d] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('branches.*') ? 'bg-[#e6915d]' : 'bg-white' }}">
                        <span class="text-[#e6915d]">█</span> Cabang
                    </a>
                    <a href="{{ route('admin-users.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#8c9ae0] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('admin-users.*') ? 'bg-[#8c9ae0]' : 'bg-white' }}">
                        <span class="text-[#8c9ae0]">█</span> User
                    </a>
                    @endif
                </nav>
            </div>

            <div class="flex-1 p-4 sm:p-6 overflow-y-auto">
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
            </div>
        </div>

        {{-- Footer --}}
        <div class="border-t-2 border-black bg-white px-4 py-3 text-center text-xs font-['Times_New_Roman'] flex-shrink-0">
            © {{ date('Y') }} Oasis CRM — Sistem Manajemen Konten Perumahan
        </div>
    </div>
</body>
</html>
