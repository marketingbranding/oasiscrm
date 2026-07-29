@props(['user'])

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }">
    <button type="button" x-ref="trigger" @click="open = !open"
            class="flex min-h-11 max-w-48 items-center gap-2 px-2 text-left hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--oasis-yellow)]"
            aria-label="Buka menu akun" :aria-expanded="open" aria-controls="oasis-account-menu">
        <span class="flex size-7 shrink-0 items-center justify-center border border-white bg-[var(--oasis-yellow)] text-xs font-black text-black" aria-hidden="true">{{ Str::upper(Str::substr($user->name, 0, 1)) }}</span>
        <span class="hidden min-w-0 sm:block">
            <span class="block truncate text-xs font-bold">{{ $user->name }}</span>
            <span class="block truncate text-[10px] font-normal text-gray-300">{{ $user->role?->name ?? 'Pengguna' }}</span>
        </span>
        <svg class="hidden size-4 shrink-0 sm:block" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 7 5 5 5-5" /></svg>
    </button>
    <div id="oasis-account-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms
         class="absolute right-0 top-full z-50 mt-2 w-64 border-2 border-black bg-white text-black shadow-xl" role="menu">
        <div class="border-b border-[var(--oasis-border)] px-4 py-3">
            <div class="truncate text-sm font-bold">{{ $user->name }}</div>
            <div class="mt-0.5 text-xs font-normal text-[var(--oasis-text-muted)]">{{ $user->role?->name ?? 'Pengguna' }}</div>
            @if($user->branch)
                <div class="mt-1 truncate text-xs font-normal text-[var(--oasis-text-muted)]">Cabang: {{ $user->branch->name }}</div>
            @endif
        </div>
        <a href="{{ route('profile.edit') }}" class="flex min-h-11 items-center px-4 text-xs font-bold hover:bg-[var(--oasis-surface-muted)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[var(--oasis-focus)]" role="menuitem">Profile</a>
        <form method="POST" action="{{ route('logout') }}" class="border-t border-[var(--oasis-border)]">
            @csrf
            <button type="submit" class="flex min-h-11 w-full items-center px-4 text-left text-xs font-bold text-[var(--oasis-danger)] hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[var(--oasis-focus)]" role="menuitem">Logout</button>
        </form>
    </div>
</div>
