@props([
    'moduleKey', 'scopeName', 'branchId' => null, 'status' => null, 'isStale' => null,
    'class' => 'px-4 py-3 mb-4',
])
@php
    $scope = ['type' => $branchId ? 'branch' : 'global', 'id' => $branchId, 'name' => $scopeName];
    $initial = app(\App\Services\SyncResponseService::class)->make($moduleKey, $scope, $status?->loadMissing('initiator'));
    $successfulAt = $status?->last_successful_at ?? $status?->finished_at;
    $initialIsStale = $isStale ?? ($initial['status'] !== 'success' || !$successfulAt || $successfulAt->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30))));
    $serverText = match ($initial['status']) {
        'syncing' => 'Sinkronisasi sedang berjalan...',
        'success' => $status?->finished_at ? 'Terakhir sync '.$status->finished_at->translatedFormat('d M Y H:i') : 'Sinkronisasi berhasil',
        'partial_success' => $initial['message'],
        'failed' => 'Sync terakhir gagal: '.$initial['message'],
        'timed_out' => 'Proses lebih lama dari biasanya. Status akhir belum diketahui.',
        default => 'Belum pernah sync. Klik Sync Sekarang.',
    };
    $serverBadge = match (true) {
        $initial['status'] === 'syncing' => 'SEDANG SINKRONISASI',
        $initial['status'] === 'failed' => 'SYNC GAGAL',
        $initial['status'] === 'partial_success' => 'PERLU DIPERIKSA',
        $initial['status'] === 'timed_out' => 'STATUS BELUM PASTI',
        $initial['status'] === 'success' && !$initialIsStale => 'DATA TERBARU',
        default => 'DATA PERLU DIPERBARUI',
    };
@endphp

<div x-data="crmSyncStatus(@js([
        'moduleKey' => $moduleKey,
        'scope' => $scope,
        'initial' => $initial,
        'initialStale' => $initialIsStale,
        'staleMinutes' => (int) config('services.google_sheets.cache_stale_minutes', 30),
    ]))"
     @oasis-sync-updated.window="applyEvent($event.detail)"
     data-sync-status-module="{{ $moduleKey }}"
     data-sync-status-scope="{{ $branchId ?: 'global' }}"
     {{ $attributes->class(['border-2 border-black bg-white flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4', $class]) }}>
    <div class="font-['Times_New_Roman'] text-sm">
        <strong class="font-bold">Status Sync:</strong>
        <span x-text="statusText">{{ $serverText }}</span>
        <span x-show="initiatedBy" class="text-gray-500">oleh <span x-text="initiatedBy">{{ $initial['initiated_by'] }}</span></span>
    </div>
    <span x-text="badgeText" :class="badgeClass" class="inline-block border-2 border-black px-2 py-1 font-[Helvetica] text-[10px] font-bold uppercase">{{ $serverBadge }}</span>
    @if($slot->isNotEmpty())
        <div class="sm:ml-auto">{{ $slot }}</div>
    @endif
</div>
