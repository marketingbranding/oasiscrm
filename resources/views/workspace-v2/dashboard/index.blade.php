@extends('workspace-v2.layouts.app')
@section('title', 'Dashboard | Workspace OASIS')
@section('content')
    @include('workspace-v2.components.page-header', ['eyebrow' => 'DASHBOARD', 'title' => 'Ruang kerja hari ini', 'description' => 'Ringkasan transaksi, antrian perhatian, dan proses yang paling aktif dalam area kerja Anda.'])
    <section class="workspace-v2-kpi-grid" aria-label="Ringkasan dashboard">
        @foreach([
            ['label' => 'Transaksi aktif', 'value' => $activeTransactions, 'note' => 'Belum selesai'],
            ['label' => 'Selesai', 'value' => $completedTransactions, 'note' => 'Akad, BAST, dan Garansi selesai'],
            ['label' => 'Perlu perhatian', 'value' => $attentionCount, 'note' => 'Kendala terbuka atau garansi berjalan'],
        ] as $kpi)
            <article class="workspace-v2-kpi"><span>{{ $kpi['label'] }}</span><strong>{{ number_format($kpi['value']) }}</strong><small>{{ $kpi['note'] }}</small></article>
        @endforeach
    </section>
    <div class="workspace-v2-dashboard-grid">
        <section class="workspace-v2-panel">
            <div class="workspace-v2-panel-heading"><div><span class="workspace-v2-eyebrow">ALUR TRANSAKSI</span><h2>Proses terbanyak</h2></div><a href="{{ route('workspace-v2.transactions') }}" class="workspace-v2-text-link">Buka semua</a></div>
            @forelse($processCounts as $process)
                @php($processLabel = match ($process->process_key) { 'data_konsumen' => 'Data Konsumen', 'bi_checking', 'slik' => 'BI Checking', 'proses_bank', 'sp3k' => 'Proses Bank', 'ppjb_dev', 'ppjb' => 'PPJB Dev', default => \Illuminate\Support\Str::headline((string) $process->process_key) })
                <div class="workspace-v2-progress-row"><span>{{ $processLabel }}</span><strong>{{ $process->total }}</strong><div class="workspace-v2-progress"><i style="width: {{ min(100, $process->total * 10) }}%"></i></div></div>
            @empty
                @include('workspace-v2.components.empty', ['compact' => true, 'title' => 'Belum ada transaksi', 'description' => 'Data transaksi yang dapat Anda lihat akan menjadi ringkasan di sini.'])
            @endforelse
        </section>
        <section class="workspace-v2-panel">
            <div class="workspace-v2-panel-heading"><div><span class="workspace-v2-eyebrow">ANTRIAN</span><h2>Perlu perhatian</h2></div><a href="{{ route('workspace-v2.kendala') }}" class="workspace-v2-text-link">Lihat kendala</a></div>
            @forelse($attentionItems as $issue)
                <a class="workspace-v2-attention-row" href="{{ route('workspace-v2.transaction.detail', $issue->application) }}" data-detail-url="{{ route('workspace-v2.transaction.detail', $issue->application) }}"><span class="workspace-v2-alert-mark">!</span><span><strong>{{ $issue->application?->customer?->name ?: 'Konsumen' }}</strong><small>{{ $issue->description }}</small></span></a>
            @empty
                @include('workspace-v2.components.empty', ['compact' => true, 'title' => 'Tidak ada antrian kritis', 'description' => 'Kendala dan garansi aktif akan muncul saat perlu tindakan.'])
            @endforelse
        </section>
    </div>
@endsection
