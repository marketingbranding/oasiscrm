@props([
    'moduleKey', 'moduleName', 'scopeName', 'syncUrl', 'statusUrl', 'status' => null,
    'branchId' => null, 'canSync' => false, 'buttonClass' => 'bg-white text-black px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black',
])
@php
    $scope = ['type' => $branchId ? 'branch' : 'global', 'id' => $branchId, 'name' => $scopeName];
    $initial = app(\App\Services\SyncResponseService::class)->make($moduleKey, $scope, $status?->loadMissing('initiator'));
@endphp

@if($canSync)
<div x-data="crmSync(@js(['key' => $moduleKey.':'.($branchId ?: 'global'), 'moduleKey' => $moduleKey, 'scope' => $scope, 'statusUrl' => $statusUrl, 'initial' => $initial]))" class="inline-block">
    <form x-ref="form" method="POST" action="{{ $syncUrl }}" @submit.prevent="submit($el)" class="inline">@csrf
        @if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
        <button x-ref="button" type="submit" @pointerdown="captureActivation($event)" :disabled="active || state === 'syncing'" :aria-busy="active || state === 'syncing'" class="{{ $buttonClass }} disabled:opacity-50 disabled:cursor-wait" x-text="active || state === 'syncing' ? 'Sedang Sinkronisasi...' : 'Sync Sekarang'"></button>
    </form>

    <div x-ref="card" x-show="open" x-cloak role="status" :aria-live="state === 'failed' ? 'assertive' : 'polite'"
         :class="interactive ? 'pointer-events-auto' : 'pointer-events-none'"
         @keydown.escape.window="if (interactive) dismiss()"
         style="transform: translate3d(12px, 12px, 0)"
         class="fixed left-0 top-0 z-[900] w-[240px] min-w-[210px] max-w-[280px] border-2 border-black bg-[#eeeeee] font-[Helvetica] text-[11px] leading-tight shadow-[4px_4px_0_#000]">
        <div class="bg-[#172554] px-2 py-1.5 text-[10px] font-bold uppercase tracking-wide text-white">
            {{ $moduleName }}@if($scopeName) — {{ $scopeName }}@endif
        </div>

        <div class="p-2" :class="state === 'success' ? 'bg-[#b3bd95] text-black' : state === 'partial_success' || state === 'timed_out' ? 'bg-[#fcc20f] text-black' : state === 'failed' ? 'bg-[#d77a7a] text-black' : 'bg-[#eeeeee] text-black'">
            <div class="flex items-center gap-2 font-bold uppercase">
                <span x-show="state === 'syncing'" aria-hidden="true" class="inline-block size-2 shrink-0 bg-[#355c8a] animate-pulse motion-reduce:animate-none"></span>
                <span x-text="stateLabel"></span>
            </div>

            <p x-show="state === 'syncing'" class="mt-1 font-mono text-[11px] font-bold" x-text="'WAKTU ' + formatElapsed(elapsed)"></p>
            <p x-show="state !== 'syncing' && result.message" class="mt-1" x-text="result.message"></p>

            <div x-show="state === 'success'" class="mt-1 space-y-0.5">
                <template x-for="entry in summaryEntries" :key="entry[0]">
                    <p><strong x-text="entry[1]"></strong> <span x-text="metricLabel(entry[0])"></span></p>
                </template>
            </div>

            <div x-show="detailsOpen" class="mt-2 border-t-2 border-black pt-1.5">
                <template x-for="entry in summaryEntries" :key="entry[0]">
                    <p class="flex justify-between gap-2"><span x-text="metricLabel(entry[0])"></span><strong x-text="entry[1]"></strong></p>
                </template>
                <p class="mt-1"><strong>Mulai:</strong> <span x-text="formatTime(result.started_at)"></span></p>
                <p><strong>Selesai:</strong> <span x-text="formatTime(result.finished_at)"></span></p>
            </div>

            <div x-show="interactive" class="mt-2 flex flex-wrap gap-1.5">
                <button x-show="state === 'failed'" type="button" @pointerdown="captureActivation($event)" @click="retry($refs.form)" :disabled="!result.retryable" class="border-2 border-black bg-black px-2 py-1 font-bold text-white disabled:opacity-50">Coba Lagi</button>
                <button x-show="state === 'partial_success' || state === 'failed'" type="button" @click="showDetails()" class="border-2 border-black bg-white px-2 py-1 font-bold">Lihat Detail</button>
                <button x-show="state === 'failed'" type="button" @click="window.dispatchEvent(new CustomEvent('open-feedback'))" class="border-2 border-black bg-white px-2 py-1 font-bold">Laporkan Masalah</button>
                <button x-show="state === 'timed_out'" type="button" @click="checkStatus()" class="border-2 border-black bg-white px-2 py-1 font-bold">Periksa Status</button>
                <button x-show="state === 'timed_out'" type="button" @click="waitLonger()" class="border-2 border-black bg-black px-2 py-1 font-bold text-white">Tetap Tunggu</button>
                <button x-show="['partial_success', 'failed', 'timed_out'].includes(state)" type="button" @click="dismiss()" class="border-2 border-black bg-white px-2 py-1 font-bold">Tutup</button>
            </div>
        </div>
    </div>
</div>
@endif
