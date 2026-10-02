<aside class="workspace-v2-sidebar" data-sidebar aria-label="Navigasi Workspace OASIS">
    <div class="workspace-v2-brand">
        <div class="workspace-v2-brand-mark">O</div>
        <div class="workspace-v2-brand-copy">
            <strong>OASIS</strong>
            <span>Workspace UAT</span>
        </div>
        <button type="button" class="workspace-v2-icon-button workspace-v2-sidebar-close" data-sidebar-close aria-label="Tutup navigasi">×</button>
    </div>
    <nav class="workspace-v2-nav">
        <a class="workspace-v2-nav-link {{ request()->routeIs('workspace-v2.dashboard') ? 'is-active' : '' }}" href="{{ route('workspace-v2.dashboard') }}">
            <span class="workspace-v2-nav-icon">⌂</span><span>Dashboard</span>
        </a>

        <div class="workspace-v2-nav-label">PENJUALAN</div>
        <a class="workspace-v2-nav-link {{ request()->routeIs('workspace-v2.lead') ? 'is-active' : '' }}" href="{{ route('workspace-v2.lead') }}">
            <span class="workspace-v2-nav-icon">↗</span><span>Lead</span>
        </a>
        <a class="workspace-v2-nav-link {{ request()->routeIs('workspace-v2.nup') ? 'is-active' : '' }}" href="{{ route('workspace-v2.nup') }}">
            <span class="workspace-v2-nav-icon">№</span><span>NUP / Waiting List</span>
        </a>
        <button type="button" class="workspace-v2-nav-link workspace-v2-nav-expander {{ request()->routeIs('workspace-v2.transactions*') ? 'is-active' : '' }}" data-process-toggle aria-expanded="true">
            <span class="workspace-v2-nav-icon">▦</span><span>Transaksi Konsumen</span><span class="workspace-v2-nav-chevron">⌄</span>
        </button>
        <div class="workspace-v2-nav-children" data-process-menu>
            @php($transactionLinks = [
                ['route' => 'workspace-v2.transactions', 'label' => 'Semua Transaksi'],
                ['route' => 'workspace-v2.transactions.data-konsumen', 'label' => 'Data Konsumen'],
                ['route' => 'workspace-v2.transactions.psjb', 'label' => 'PSJB'],
                ['route' => 'workspace-v2.transactions.bi-checking', 'label' => 'BI Checking'],
                ['route' => 'workspace-v2.transactions.pemberkasan', 'label' => 'Pemberkasan'],
                ['route' => 'workspace-v2.transactions.proses-bank', 'label' => 'Proses Bank'],
                ['route' => 'workspace-v2.transactions.ppjb-dev', 'label' => 'PPJB Dev'],
                ['route' => 'workspace-v2.transactions.akad', 'label' => 'Akad'],
                ['route' => 'workspace-v2.transactions.bast', 'label' => 'BAST'],
            ])
            @foreach($transactionLinks as $link)
                <a class="workspace-v2-nav-child {{ request()->routeIs($link['route']) ? 'is-active' : '' }}" href="{{ route($link['route']) }}">{{ $link['label'] }}</a>
            @endforeach
        </div>
        @foreach([
            ['route' => 'workspace-v2.mundur', 'label' => 'Mundur', 'icon' => '−'],
            ['route' => 'workspace-v2.kendala', 'label' => 'Kendala', 'icon' => '!'],
            ['route' => 'workspace-v2.garansi', 'label' => 'Garansi', 'icon' => '↺'],
            ['route' => 'workspace-v2.selesai', 'label' => 'Selesai', 'icon' => '✓'],
        ] as $link)
            <a class="workspace-v2-nav-link {{ request()->routeIs($link['route']) ? 'is-active' : '' }}" href="{{ route($link['route']) }}"><span class="workspace-v2-nav-icon">{{ $link['icon'] }}</span><span>{{ $link['label'] }}</span></a>
        @endforeach

        <div class="workspace-v2-nav-label">AKTIVITAS</div>
        @foreach([
            ['route' => 'workspace-v2.activity', 'label' => 'Aktivitas'],
            ['route' => 'workspace-v2.tasks', 'label' => 'Tugas'],
            ['route' => 'workspace-v2.notifications', 'label' => 'Notifikasi'],
        ] as $link)
            <a class="workspace-v2-nav-link {{ request()->routeIs($link['route']) ? 'is-active' : '' }}" href="{{ route($link['route']) }}"><span class="workspace-v2-nav-icon">·</span><span>{{ $link['label'] }}</span></a>
        @endforeach

        <div class="workspace-v2-nav-label">LAPORAN</div>
        <a class="workspace-v2-nav-link {{ request()->routeIs('workspace-v2.reports') ? 'is-active' : '' }}" href="{{ route('workspace-v2.reports') }}"><span class="workspace-v2-nav-icon">▤</span><span>Laporan</span></a>

        <div class="workspace-v2-nav-label">SISTEM</div>
        <a class="workspace-v2-nav-link {{ request()->routeIs('workspace-v2.settings') ? 'is-active' : '' }}" href="{{ route('workspace-v2.settings') }}"><span class="workspace-v2-nav-icon">⚙</span><span>Pengaturan</span></a>
    </nav>
    <div class="workspace-v2-sidebar-footer">
        <span class="workspace-v2-status-dot"></span>
        <span>Mode UAT aktif</span>
    </div>
</aside>
