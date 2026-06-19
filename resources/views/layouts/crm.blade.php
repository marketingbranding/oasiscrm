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
    <div class="flex flex-col sm:flex-row pt-14">
        <div :class="sidebarOpen ? 'block' : 'hidden'"
             class="sm:block group w-full sm:fixed sm:left-0 sm:top-14 sm:bottom-0 sm:z-40 sm:w-12 sm:hover:w-56 sm:transition-all sm:duration-200 sm:overflow-x-hidden sm:whitespace-nowrap bg-white sm:border-r-2 border-black">
            <nav class="p-2">
                <a href="{{ route('dashboard') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#9ab6c8] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('dashboard') ? 'bg-[#9ab6c8]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#9ab6c8] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">Dashboard</span>
                </a>
                <a href="{{ route('content-calendar.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#b3bd95] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('content-calendar.*') ? 'bg-[#b3bd95]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#b3bd95] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">Content Calendar</span>
                </a>
                <a href="{{ route('database.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#d77a7a] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('database.*') ? 'bg-[#d77a7a]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#d77a7a] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">Database</span>
                </a>
                <a href="{{ route('lead-events.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#e6915d] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('lead-events.*') ? 'bg-[#e6915d]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#e6915d] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">Daftar Event</span>
                </a>
                <div class="border-t border-dashed border-gray-400 mx-2 my-2"></div>
                <a href="{{ route('lead-daily.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#c0392b] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('lead-daily.*') ? 'bg-[#c0392b]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#c0392b] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">Lead Harian</span>
                </a>
                <div class="border-t border-dashed border-gray-400 mx-2 my-2"></div>
                <a href="{{ route('dana-talangan.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#f1c40f] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('dana-talangan.*') ? 'bg-[#f1c40f]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#f1c40f] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">Dana Talangan</span>
                </a>
                @if(Auth::user() && Auth::user()->isSuperadmin())
                <div class="border-t border-dashed border-gray-400 mx-2 my-2 pt-1 text-[10px] text-gray-500 text-center tracking-[0.2em]">── Pengaturan ──</div>
                <a href="{{ route('branches.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#e6915d] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('branches.*') ? 'bg-[#e6915d]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#e6915d] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">Cabang</span>
                </a>
                <a href="{{ route('projects.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#5d8e8e] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('projects.*') ? 'bg-[#5d8e8e]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#5d8e8e] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75a4.5 4.5 0 0 1-4.884 4.484c-1.076-.091-2.264.071-2.95.904l-7.152 8.684a2.548 2.548 0 1 1-3.586-3.586l8.684-7.152c.833-.686.995-1.874.904-2.95a4.5 4.5 0 0 1 6.336-4.486l-3.276 3.276a3.004 3.004 0 0 0 2.25 2.25l3.276-3.276c.256.565.398 1.192.398 1.852Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.867 19.125h.008v.008h-.008v-.008Z"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">Proyek</span>
                </a>
                <a href="{{ route('admin-users.index') }}" class="flex items-center px-3 py-2 gap-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#8c9ae0] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('admin-users.*') ? 'bg-[#8c9ae0]' : 'bg-white' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-[#8c9ae0] inline-block w-4 h-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg> <span class="sm:w-0 sm:overflow-hidden sm:whitespace-nowrap group-hover:sm:w-auto transition-all duration-200 delay-150">User</span>
                </a>
                @endif
            </nav>
        </div>

        <div class="flex-1 p-4 sm:p-6 sm:ml-12">
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
<div x-data="{ showBug: false, message: '', sending: false, sent: false }" class="fixed bottom-4 right-4 z-50">
    <template x-if="!showBug">
        <button @click="showBug = true; sent = false"
                class="w-12 h-12 bg-[#c0392b] hover:bg-[#a93226] text-white border-2 border-black rounded-none flex items-center justify-center shadow-lg transition-colors duration-200"
                title="Laporkan Bug">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z"/>
            </svg>
        </button>
    </template>
    <template x-if="showBug">
        <div class="bg-white border-2 border-black shadow-xl w-80 font-['Times_New_Roman']">
            <div class="bg-[#c0392b] text-white px-3 py-2 flex items-center justify-between text-sm font-bold font-[Helvetica]">
                <span>Laporkan Bug</span>
                <button @click="showBug = false" class="hover:text-gray-300 text-lg leading-none">&times;</button>
            </div>
            <div class="p-3">
                <template x-if="sent">
                    <div class="text-center py-4 text-sm text-green-700 font-bold border-2 border-green-700 bg-green-50 px-3 py-2">
                        Terkirim! Terima kasih atas laporannya.
                        <button @click="showBug = false" class="block mx-auto mt-2 text-xs text-gray-500 underline">Tutup</button>
                    </div>
                </template>
                <template x-if="!sent">
                    <form @submit.prevent="sending = true; errorMsg = ''; fetch('{{ route('bug-report.store') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ message }) }).then(async r => { const text = await r.text(); try { return JSON.parse(text); } catch { return { ok: false, error: text.substring(0,200) }; } }).then(d => { sending = false; if (d.ok) { sent = true; message = ''; } else { alert('Gagal: ' + (d.error || d.message || 'Coba lagi.')); } }).catch(e => { sending = false; alert('Gagal: ' + e.message); })">
                        <label class="block text-xs font-bold mb-1">Jelaskan bug yang ditemukan:</label>
                        <textarea x-model="message" required maxlength="2000" rows="4"
                                  class="w-full border-2 border-black px-2 py-1.5 text-sm resize-none focus:outline-none"
                                  placeholder="Contoh: Tombol submit tidak berfungsi di halaman Lead Harian..."></textarea>
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-xs text-gray-500" x-text="message.length + '/2000'"></span>
                            <button type="submit" :disabled="sending || !message.trim()"
                                    class="bg-[#e6915d] hover:bg-[#d4854f] text-black border-2 border-black px-4 py-1 text-sm font-bold font-[Helvetica] disabled:opacity-40 transition-colors duration-200"
                                    x-text="sending ? 'Mengirim...' : 'Kirim Bug'"></button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </template>
</div>
</body>
</html>
