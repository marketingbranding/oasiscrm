@php
    $tone = match (strtolower((string) $value)) {
        'selesai', 'complete', 'completed', 'lanjut', 'aktif', 'approved', 'disetujui' => 'success',
        'mundur', 'reject', 'rejected', 'ditolak', 'error' => 'danger',
        'pending', 'menunggu', 'proses', 'draft' => 'warning',
        default => 'neutral',
    };
@endphp
<span class="workspace-v2-status workspace-v2-status--{{ $tone }}">{{ $value ?: 'Belum diisi' }}</span>
