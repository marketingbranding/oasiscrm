@extends('layouts.crm')

@section('title', 'Struktur Organisasi')

@section('content')
<div x-data="organizationWorkspace(@js($flatNodes), @js(route('organization.move', ['user' => '__USER__'])), @js($canMove))" class="space-y-5 p-4 md:p-6" @keydown.escape.window="context = null; confirmation = false; cancelConnection()">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b-2 border-black pb-4">
        <div>
            <p class="font-[Helvetica] text-xs font-bold uppercase tracking-[0.16em] text-gray-600">Administrasi / Organisasi</p>
            <h1 class="mt-1 font-['Arial_Black'] text-2xl uppercase tracking-tight md:text-3xl">Struktur Organisasi</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-700">Atur garis pelaporan tanpa mengubah primary role atau akses cabang dan proyek.</p>
        </div>
        <div class="flex flex-wrap gap-2 font-[Helvetica] text-sm">
            <a href="{{ route('admin-users.index') }}" class="border-2 border-black bg-white px-3 py-2 font-bold hover:bg-[var(--oasis-yellow)]">Atur Pengguna</a>
            <span class="border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-bold">Role != Atasan</span>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3 border-2 border-black bg-white p-3">
        <label class="flex min-w-52 flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">
            Cabang
            <select onchange="this.form.submit()" form="organization-filters" name="branch_id" class="border-2 border-black bg-white px-3 py-2 text-sm font-normal">
                <option value="">Semua cabang yang terlihat</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($selectedBranchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
        <form id="organization-filters" method="GET" action="{{ route('organization.index') }}"></form>
        <label class="flex min-w-64 flex-1 flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">
            Cari pengguna
            <input type="search" x-model.debounce.150ms="search" placeholder="Nama pengguna..." class="border-2 border-black px-3 py-2 text-sm font-normal">
        </label>
        <div class="flex flex-wrap items-center gap-2 font-[Helvetica] text-xs font-bold uppercase">
            <button type="button" @click="fitView()" class="min-h-11 border-2 border-black bg-white px-3 py-2 hover:bg-[var(--oasis-yellow)]">Fit view</button>
            <button type="button" @click="resetView()" class="min-h-11 border-2 border-black bg-white px-3 py-2 hover:bg-[var(--oasis-yellow)]">Reset</button>
            <button type="button" @click="toggleFullscreen()" class="min-h-11 border-2 border-black bg-white px-3 py-2 hover:bg-[var(--oasis-yellow)]">Fullscreen</button>
        </div>
        <p class="w-full font-[Helvetica] text-xs text-gray-600">Geser kanvas untuk melihat area lain. Tarik socket <strong>Atasan</strong> atau <strong>Bawahan</strong> ke socket node lain untuk memindahkan garis pelaporan.</p>
    </div>

    <div class="org-workspace">
        <section class="hidden min-h-[32rem] min-w-0 overflow-hidden border-2 border-black bg-[#f5f0df] md:flex md:flex-col" aria-label="Kanvas struktur organisasi">
            <div class="flex items-center justify-between border-b-2 border-black bg-black px-3 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">
                <span>Kanvas Organisasi</span>
                <span class="flex flex-wrap items-center justify-end gap-2">
                    <button type="button" x-show="selected && canMove" x-cloak @click="openMove()" class="border border-white bg-[var(--oasis-yellow)] px-2 py-1 text-[10px] font-bold text-black">Pindahkan node terpilih</button>
                    <span x-show="selected" x-cloak class="border border-gray-500 px-2 py-1 normal-case text-gray-200"><span class="text-gray-400">Terpilih:</span> <span x-text="selected?.name"></span></span>
                    <span class="border border-gray-500 px-2 py-1 text-gray-200" aria-label="Zoom" x-text="`${Math.round(scale * 100)}%`"></span>
                </span>
            </div>
            <div x-ref="canvas" tabindex="0" class="org-canvas relative min-h-0 flex-1 overflow-hidden cursor-grab outline-none active:cursor-grabbing" @pointerdown="startPan($event)" @pointermove="handlePointerMove($event)" @pointerup="endPointerInteraction($event)" @pointercancel="cancelConnection()" @pointerleave="endPointerInteraction($event)" @wheel.prevent="zoom($event)">
                <div x-ref="graph" class="org-graph absolute left-6 top-6 z-10 origin-top-left" :style="graphStyle()">
                    <svg class="pointer-events-none absolute inset-0 z-0 size-full overflow-visible" aria-hidden="true">
                        <path :d="connectorPath()" fill="none" stroke="#111" stroke-width="2" />
                        <path x-show="activeConnection" :d="connectionPreviewPath()" fill="none" stroke="var(--oasis-yellow)" stroke-width="3" stroke-dasharray="7 5" />
                    </svg>
                    <div class="relative z-10">
                        @foreach($flatNodes as $node)
                            @include('crm.organization._node', ['node' => $node])
                        @endforeach
                    </div>
                </div>
                @if($nodes === [])
                    <div class="absolute inset-0 z-20 flex items-center justify-center p-6 text-center"><div class="border-2 border-black bg-white p-5"><strong class="block font-[Helvetica] text-sm uppercase">Tidak ada pengguna pada cakupan ini</strong><span class="mt-1 block text-sm text-gray-600">Pilih cabang lain atau hapus filter cabang.</span></div></div>
                @endif
            </div>
        </section>

        <section class="border-2 border-black bg-white p-3 md:hidden" aria-label="Daftar hierarki organisasi">
            <div class="mb-3 border-b-2 border-black pb-2 font-[Helvetica] text-xs font-bold uppercase">Daftar Hierarki</div>
            <div class="space-y-2">
                @foreach($nodes as $node)
                    @include('crm.organization._mobile-node', ['node' => $node, 'depth' => 0])
                @endforeach
                @if($nodes === [])<p class="border-2 border-dashed border-black p-4 text-sm text-gray-600">Tidak ada pengguna pada cakupan ini.</p>@endif
            </div>
        </section>
    </div>

    <div x-show="context" x-cloak class="fixed inset-0 z-40">
        <div x-ref="contextCard" class="org-context-card fixed z-50 w-[min(19rem,calc(100vw-1.5rem))] border-2 border-black bg-white p-3 shadow-[4px_4px_0_#000]" :style="contextStyle" role="dialog" aria-modal="false" aria-labelledby="organization-context-title" @click.outside="closeContext()">
            <div class="flex items-start justify-between gap-3 border-b-2 border-black pb-3">
                <div><p class="font-[Helvetica] text-[10px] font-bold uppercase text-gray-600">Tindakan node</p><h2 id="organization-context-title" class="mt-1 text-lg font-bold" x-text="context?.name"></h2></div>
                <button type="button" class="min-h-11 min-w-11 border-2 border-black font-[Helvetica] text-xs font-bold" @click="closeContext()" aria-label="Tutup tindakan node">X</button>
            </div>
            <dl class="grid gap-2 py-3 text-sm">
                <div><dt class="font-[Helvetica] text-[10px] font-bold uppercase text-gray-600">Primary role</dt><dd x-text="context?.role"></dd></div>
                <div><dt class="font-[Helvetica] text-[10px] font-bold uppercase text-gray-600">Cabang</dt><dd x-text="context?.branch || 'Tidak ditentukan'"></dd></div>
                <div><dt class="font-[Helvetica] text-[10px] font-bold uppercase text-gray-600">Direct reports</dt><dd x-text="context?.direct_reports"></dd></div>
            </dl>
            <p class="border-t border-gray-300 pt-3 text-xs text-gray-600">Geser kartu tidak mengubah data. Perpindahan garis pelaporan akan divalidasi oleh server.</p>
            <button type="button" x-show="canMove" x-cloak @click="openMove()" class="mt-3 min-h-11 w-full border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-[Helvetica] text-xs font-bold uppercase">Pindahkan garis pelaporan</button>
        </div>
    </div>

    <div x-show="confirmation" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="organization-confirm-title">
        <div class="w-full max-w-md border-2 border-black bg-white p-5">
            <h2 id="organization-confirm-title" class="text-xl font-bold">Konfirmasi perpindahan</h2>
            <p class="mt-3 text-sm">Pindahkan <strong x-text="selected?.name"></strong> ke <strong x-text="parentName"></strong>?</p>
            <p class="mt-1 text-xs text-gray-600">Peran pengguna tidak berubah. Berlaku hari ini.</p>
            <label class="mt-4 flex flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">Atasan baru
                <select x-model="newParentId" class="border-2 border-black bg-white px-3 py-2 text-sm font-normal">
                    <option value="">Tanpa atasan</option>
                    <template x-for="node in moveTargets" :key="node.id"><option :value="node.id" x-text="`${node.name} - ${node.role}`"></option></template>
                </select>
            </label>
            <div class="mt-5 flex justify-end gap-2 font-[Helvetica] text-sm">
                <button type="button" @click="confirmation = false" class="border-2 border-black bg-white px-3 py-2 font-bold">Batal</button>
                <button type="button" @click="commitMove()" class="border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-bold" :disabled="saving || String(newParentId) === String(selected?.parent_id || '')" x-text="saving ? 'Menyimpan...' : 'Pindahkan'"></button>
            </div>
        </div>
    </div>
</div>
@endsection
