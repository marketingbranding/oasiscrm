<header class="workspace-v2-topbar">
    <button type="button" class="workspace-v2-icon-button workspace-v2-menu-button" data-sidebar-open aria-label="Buka navigasi">☰</button>
    <div class="workspace-v2-topbar-context">
        <span class="workspace-v2-eyebrow">OASIS / WORKSPACE</span>
        <span class="workspace-v2-topbar-subtitle">Area kerja operasional</span>
    </div>
    <div class="workspace-v2-topbar-actions">
        <a href="{{ route('notifications.index') }}" class="workspace-v2-topbar-link">Notifikasi</a>
        <span class="workspace-v2-user">{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="workspace-v2-topbar-link">Keluar</button>
        </form>
    </div>
</header>
