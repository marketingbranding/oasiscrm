@extends('workspace-v2.layouts.app')
@section('title', 'NUP / Waiting List | Workspace OASIS')
@section('content')
    @include('workspace-v2.components.page-header', ['eyebrow' => 'PENJUALAN', 'title' => 'NUP / Waiting List', 'description' => 'Daftar peminat yang belum memiliki kavling dan dapat dilanjutkan menjadi konsumen.', 'action' => '<a class="workspace-v2-button workspace-v2-button--primary" href="'.route('consumer-nups.create').'">+ NUP baru</a>'])
    <form method="GET" class="workspace-v2-toolbar"><label class="workspace-v2-search"><span>⌕</span><input type="search" name="search" value="{{ $search }}" placeholder="Cari NUP, nama, atau no HP..." aria-label="Cari NUP"></label><button class="workspace-v2-button workspace-v2-button--secondary">Cari</button></form>
    <section class="workspace-v2-table-panel">
        @if($nups->count())
            <div class="workspace-v2-table-wrap"><table class="workspace-v2-table"><thead><tr><th>NUP</th><th>Peminat</th><th>Proyek</th><th>Terdaftar</th><th>Status</th><th><span class="sr-only">Aksi</span></th></tr></thead><tbody>
                @foreach($nups as $nup)<tr><td class="workspace-v2-mono"><strong>{{ $nup->nup_number }}</strong><small>{{ $nup->branch?->name }}</small></td><td><strong>{{ $nup->customer?->name ?: 'Nama belum diisi' }}</strong><small>{{ $nup->customer?->phone ?: 'No HP belum diisi' }}</small></td><td>{{ $nup->project?->project_name ?: 'Tanpa proyek' }}</td><td>{{ $nup->registered_at?->format('d/m/Y') }}</td><td>@include('workspace-v2.components.status', ['value' => $nup->status ?: 'Menunggu'])</td><td><a class="workspace-v2-row-action" href="{{ route('consumer-nups.index') }}">Kelola</a></td></tr><tr class="workspace-v2-mobile-card-row"><td colspan="6"><article class="workspace-v2-mobile-card"><div><strong>{{ $nup->nup_number }}</strong>@include('workspace-v2.components.status', ['value' => $nup->status ?: 'Menunggu'])</div><span>{{ $nup->customer?->name ?: 'Nama belum diisi' }}</span><small>{{ $nup->project?->project_name ?: 'Tanpa proyek' }} · {{ $nup->registered_at?->format('d/m/Y') }}</small><a class="workspace-v2-row-action" href="{{ route('consumer-nups.index') }}">Kelola waiting list</a></article></td></tr>@endforeach
            </tbody></table></div><div class="workspace-v2-pagination">{{ $nups->links() }}</div>
        @else @include('workspace-v2.components.empty', ['title' => 'Waiting list kosong', 'description' => 'NUP baru dan peminat dalam area kerja Anda akan muncul di sini.']) @endif
    </section>
@endsection
