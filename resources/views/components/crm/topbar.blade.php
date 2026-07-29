@props(['navigation', 'overdueItems', 'todayItems', 'tomorrowItems', 'needsConfirmation'])

@php
    $user = Auth::user();
    $activeGroup = collect($navigation)->firstWhere('active', true);
@endphp

<header class="fixed inset-x-0 top-0 z-50 flex h-[var(--oasis-topbar-height)] items-center justify-between border-b border-white/15 bg-[var(--oasis-topbar-bg)] px-2 font-[Helvetica] text-sm font-bold text-white sm:px-4">
    <div class="flex min-w-0 items-center gap-2">
        <button type="button" @click="sidebarOpen = !sidebarOpen"
                class="flex size-11 shrink-0 items-center justify-center hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--oasis-yellow)] md:hidden"
                aria-label="Buka navigasi utama" :aria-expanded="sidebarOpen" aria-controls="crm-sidebar">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
        </button>
        <span class="text-lg text-[var(--oasis-yellow)]" aria-hidden="true">◆</span>
        <a href="{{ route($user->landingRouteName()) }}" class="truncate tracking-wide hover:text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--oasis-yellow)]">OASIS CRM</a>
        @if($activeGroup)
            <span class="hidden h-5 w-px bg-white/25 lg:block" aria-hidden="true"></span>
            <span class="hidden truncate text-xs font-normal text-gray-300 lg:block">{{ $activeGroup['label'] }}</span>
        @endif
    </div>

    <div class="flex shrink-0 items-center gap-1">
        <x-crm.notification-menu :$overdueItems :$todayItems :$tomorrowItems :$needsConfirmation />
        <x-crm.account-menu :user="$user" />
    </div>
</header>
