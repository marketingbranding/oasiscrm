@extends('workspace-v2.layouts.app')
@section('title', 'Aktivitas | Workspace OASIS')
@section('content')
    @include('workspace-v2.components.page-header', ['eyebrow' => 'AKTIVITAS', 'title' => 'Aktivitas', 'description' => 'Riwayat perubahan dan notifikasi akun dalam satu area kerja.'])
    <div class="workspace-v2-dashboard-grid">
        <section class="workspace-v2-panel"><div class="workspace-v2-panel-heading"><div><span class="workspace-v2-eyebrow">AUDIT</span><h2>Aktivitas Anda</h2></div></div>@forelse($activities as $activity)<div class="workspace-v2-activity-row"><span class="workspace-v2-activity-time">{{ $activity->created_at?->format('d/m H:i') }}</span><span><strong>{{ $activity->event }}</strong><small>{{ $activity->description }}</small></span></div>@empty @include('workspace-v2.components.empty', ['title' => 'Belum ada aktivitas', 'description' => 'Aktivitas perubahan yang Anda lakukan akan tercatat di sini.']) @endforelse</section>
        <section class="workspace-v2-panel"><div class="workspace-v2-panel-heading"><div><span class="workspace-v2-eyebrow">INBOX</span><h2>Notifikasi</h2></div></div>@forelse($notifications as $notification)<div class="workspace-v2-activity-row"><span class="workspace-v2-notification-dot {{ $notification->read_at ? 'is-read' : '' }}"></span><span><strong>{{ $notification->title }}</strong><small>{{ $notification->body }}</small></span></div>@empty @include('workspace-v2.components.empty', ['title' => 'Tidak ada notifikasi', 'description' => 'Notifikasi baru akan masuk ke inbox Anda.']) @endforelse</section>
    </div>
@endsection
