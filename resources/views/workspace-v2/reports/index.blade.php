@extends('workspace-v2.layouts.app')
@section('title', 'Laporan | Workspace OASIS')
@section('content')
    @include('workspace-v2.components.page-header', ['eyebrow' => 'LAPORAN', 'title' => 'Laporan', 'description' => 'Ringkasan query operasional yang tersedia, tanpa report builder baru.'])
    <section class="workspace-v2-kpi-grid"><article class="workspace-v2-kpi"><span>Total transaksi</span><strong>{{ number_format($total) }}</strong><small>Dalam area kerja Anda</small></article><article class="workspace-v2-kpi"><span>Transaksi berjalan</span><strong>{{ number_format($active) }}</strong><small>Belum selesai</small></article><article class="workspace-v2-kpi"><span>Transaksi selesai</span><strong>{{ number_format($completed) }}</strong><small>Lifecycle selesai</small></article></section>
    <section class="workspace-v2-panel"><div class="workspace-v2-panel-heading"><div><span class="workspace-v2-eyebrow">CAKUPAN KERJA</span><h2>Cabang yang tersedia</h2></div></div><div class="workspace-v2-chip-list">@forelse($branches as $branch)<span class="workspace-v2-chip">{{ $branch->name }}</span>@empty<span class="workspace-v2-muted">Tidak ada cabang yang tersedia.</span>@endforelse</div></section>
@endsection
