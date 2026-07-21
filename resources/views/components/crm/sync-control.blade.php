@props([
    'moduleKey', 'moduleName', 'scopeName', 'syncUrl', 'statusUrl', 'status' => null,
    'branchId' => null, 'canSync' => false, 'buttonClass' => 'bg-white text-black px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black',
])
@php
    $scope = ['type' => $branchId ? 'branch' : 'global', 'id' => $branchId, 'name' => $scopeName];
    $initial = app(\App\Services\SyncResponseService::class)->make($moduleKey, $scope, $status?->loadMissing('initiator'));
@endphp

@if($canSync)
<div x-data="crmSync(@js(['key' => $moduleKey.':'.($branchId ?: 'global'), 'statusUrl' => $statusUrl, 'initial' => $initial]))" class="inline-block">
    <form x-ref="form" method="POST" action="{{ $syncUrl }}" @submit.prevent="submit($el)" class="inline">@csrf
        @if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
        <button type="submit" :disabled="active || state === 'syncing'" aria-live="polite" :aria-busy="active || state === 'syncing'" class="{{ $buttonClass }} disabled:opacity-50 disabled:cursor-wait" x-text="active || state === 'syncing' ? 'Sedang Sinkronisasi...' : 'Sync Sekarang'"></button>
    </form>

    <div x-show="open" x-cloak class="fixed inset-0 z-[900] flex items-center justify-center bg-black/70 px-4" @keydown.escape.window="if (terminal || state === 'timed_out') open = false">
        <div x-ref="dialog" tabindex="-1" role="dialog" aria-modal="true" aria-live="polite" class="w-full max-w-lg border-2 border-black bg-white shadow-[8px_8px_0_0_#000]">
            <div class="flex items-center justify-between bg-black text-white px-4 py-2"><strong>{{ $moduleName }} {{ $scopeName }}</strong><button type="button" @click="open=false" :disabled="state === 'syncing'" aria-label="Tutup">&times;</button></div>
            <div class="p-4 space-y-3 text-sm">
                <div class="border-2 border-black px-3 py-2" :class="state === 'failed' ? 'bg-[#d77a7a]' : state === 'partial_success' || state === 'timed_out' ? 'bg-[#fcc20f]' : state === 'success' ? 'bg-[#b3bd95]' : 'bg-[#eef1ff]'">
                    <strong x-text="stateLabel"></strong><p x-text="result.message || (state === 'syncing' ? 'Sedang menghubungkan dan memproses data Google Sheets.' : '')"></p>
                </div>
                <div x-show="state === 'syncing'"><p>Jangan menutup halaman ini. Waktu proses bergantung pada jumlah data.</p><p class="font-bold mt-1" x-text="'Berjalan ' + formatElapsed(elapsed)"></p></div>
                <div x-show="terminal || state === 'timed_out'" class="grid grid-cols-2 gap-2 text-xs">
                    <p><strong>Mulai:</strong> <span x-text="formatTime(result.started_at)"></span></p><p><strong>Selesai:</strong> <span x-text="formatTime(result.finished_at)"></span></p>
                    <p><strong>Durasi:</strong> <span x-text="result.duration_ms != null ? formatElapsed(Math.round(result.duration_ms / 1000)) : formatElapsed(elapsed)"></span></p><p><strong>Terakhir berhasil:</strong> <span x-text="formatTime(result.last_successful_sync_at)"></span></p>
                </div>
                <div x-show="summaryEntries.length" class="border-2 border-black"><template x-for="entry in summaryEntries" :key="entry[0]"><div class="flex justify-between border-b border-black last:border-b-0 px-3 py-1"><span x-text="entry[0].replaceAll('_',' ')"></span><strong x-text="entry[1]"></strong></div></template></div>
                <p x-show="state === 'failed' && result.local_data_changed === false">Data lokal belum diperbarui. Anda dapat mencoba kembali.</p>
                <div class="flex flex-wrap gap-2">
                    <button x-show="state === 'failed' && result.retryable" type="button" @click="retry($refs.form)" class="border-2 border-black bg-black text-white px-3 py-1 font-bold">Coba Lagi</button>
                    <button x-show="state === 'timed_out'" type="button" @click="waitLonger()" class="border-2 border-black bg-black text-white px-3 py-1 font-bold">Tunggu</button>
                    <button x-show="state === 'timed_out' || state === 'syncing'" type="button" @click="checkStatus()" class="border-2 border-black bg-white px-3 py-1 font-bold">Periksa Status</button>
                    <button x-show="terminal || state === 'timed_out'" type="button" @click="open=false" class="border-2 border-black bg-white px-3 py-1 font-bold">Tutup</button>
                    <button x-show="state === 'failed'" type="button" @click="window.dispatchEvent(new CustomEvent('open-feedback'))" class="border-2 border-black bg-white px-3 py-1 font-bold">Laporkan Masalah</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif
