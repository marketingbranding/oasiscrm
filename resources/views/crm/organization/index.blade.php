@extends('layouts.crm')

@section('title', 'Struktur Organisasi')

@section('content')
<div x-data="organizationWorkspace(@js($flatNodes), @js(route('organization.move', ['user' => '__USER__'])), @js($unitMoveUrl), @js($removeStructureUrl), @js($movableUnitIds), @js($canMove))" class="space-y-5 p-4 md:p-6" @keydown.escape.window="context = null; confirmation = false; unitConfirmation = false; removeConfirmation = false; cancelConnection()" @keydown.delete.window="requestRemoveSelected()">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b-2 border-black pb-4">
        <div>
            <p class="font-[Helvetica] text-xs font-bold uppercase tracking-[0.16em] text-gray-600">Administrasi / Organisasi</p>
            <h1 class="mt-1 font-['Arial_Black'] text-2xl uppercase tracking-tight md:text-3xl">Struktur Organisasi</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-700">Atur penempatan Pusat/Cabang tanpa mengubah primary role atau akses cabang dan proyek.</p>
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
            <button type="button" @click="viewMode = 'zones'" class="min-h-11 border-2 border-black px-3 py-2" :class="viewMode === 'zones' ? 'bg-[var(--oasis-yellow)]' : 'bg-white'">Area unit</button>
            <button type="button" @click="viewMode = 'graph'; $nextTick(() => fitView())" class="min-h-11 border-2 border-black bg-white px-3 py-2" :class="viewMode === 'graph' ? 'bg-[var(--oasis-yellow)]' : 'bg-white'">Lihat hierarchy</button>
            <button type="button" x-show="viewMode === 'graph'" x-cloak @click="fitView()" class="min-h-11 border-2 border-black bg-white px-3 py-2">Fit view</button>
            <button type="button" x-show="viewMode === 'graph'" x-cloak @click="resetView()" class="min-h-11 border-2 border-black bg-white px-3 py-2">Reset</button>
            <button type="button" x-show="viewMode === 'graph'" x-cloak @click="toggleFullscreen()" class="min-h-11 border-2 border-black bg-white px-3 py-2">Fullscreen</button>
            <button type="button" x-show="viewMode === 'graph'" x-cloak @click="showUnitLinks = !showUnitLinks" class="min-h-11 border-2 border-black px-3 py-2" :class="showUnitLinks ? 'bg-[#e8eddc]' : 'bg-white'" x-text="showUnitLinks ? 'Sembunyikan relasi unit' : 'Tampilkan relasi unit'"></button>
        </div>
        <p class="w-full font-[Helvetica] text-xs text-gray-600">Tarik kartu pengguna ke area Pusat atau Cabang untuk memindahkan unit. Tekan <strong>Delete</strong> untuk mengeluarkan pengguna dari struktur tanpa menghapus akun.</p>
    </div>

    <div class="org-workspace">
        <section x-show="viewMode === 'zones'" class="hidden min-h-[32rem] min-w-0 border-2 border-black bg-[#f5f0df] md:block" aria-label="Area unit organisasi">
            <div class="flex items-center justify-between border-b-2 border-black bg-black px-3 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">
                <span>Area Unit Organisasi</span>
                <span class="border border-gray-500 px-2 py-1 text-gray-200">Seret user ke area</span>
            </div>
            <div class="grid gap-4 p-4 xl:grid-cols-2">
                <template x-for="zone in zones" :key="zone.id">
                    <section class="org-unit-zone" :class="zone.unit_id === null ? 'org-unit-zone-unassigned' : (zone.role === 'Pusat organisasi' ? 'org-unit-zone-central' : 'org-unit-zone-branch')" @dragover.prevent @drop.prevent="dropZone($event, zone.id)">
                        <header class="flex items-start justify-between gap-3 border-b-2 border-black pb-3">
                            <div><p class="font-[Helvetica] text-[10px] font-bold uppercase" x-text="zone.role"></p><h2 class="mt-1 text-xl font-bold" x-text="zone.name"></h2><p class="text-xs text-gray-600" x-text="zone.branch || (zone.unit_id === null ? 'User tanpa penempatan organisasi' : 'Semua cabang pusat')"></p></div>
                            <span class="border-2 border-black bg-white px-2 py-1 font-[Helvetica] text-xs font-bold" x-text="`${zone.users.length} user`"></span>
                        </header>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <template x-for="user in zone.users" :key="user.id">
                                <button type="button" draggable="true" @dragstart="startZoneDrag($event, user.id)" @click="select(user.id)" @keydown.delete.stop="requestRemoveSelected()" class="org-zone-user-card border-2 border-black bg-white p-3 text-left" :class="selected?.id === user.id ? 'ring-4 ring-[var(--oasis-focus)] ring-offset-2' : ''">
                                    <strong class="block truncate text-sm" x-text="user.name"></strong>
                                    <span class="mt-1 block truncate text-xs text-gray-600" x-text="user.role"></span>
                                    <span class="mt-2 block text-[10px] font-bold uppercase text-gray-500" x-text="user.parent_id ? `Atasan: ${nodes.find((node) => String(node.id) === String(user.parent_id))?.name || '-'}` : 'Tanpa atasan'"></span>
                                </button>
                            </template>
                            <p x-show="zone.users.length === 0" class="border-2 border-dashed border-black p-4 text-sm text-gray-600 sm:col-span-2">Drop user di area ini.</p>
                        </div>
                    </section>
                </template>
            </div>
        </section>

        <section x-show="viewMode === 'graph'" x-cloak class="hidden min-h-[32rem] min-w-0 overflow-hidden border-2 border-black bg-[#f5f0df] md:flex md:flex-col" aria-label="Kanvas struktur organisasi">
            <div class="flex items-center justify-between border-b-2 border-black bg-black px-3 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">
                <span>Kanvas Organisasi</span>
                <span class="flex flex-wrap items-center justify-end gap-2">
                    <span class="border border-gray-500 px-2 py-1 text-[10px] text-gray-200"><span class="mr-1 inline-block size-2 bg-white align-middle"></span>Hierarchy</span>
                    <span class="border border-gray-500 px-2 py-1 text-[10px] text-gray-200"><span class="mr-1 inline-block size-2 bg-[#b3bd95] align-middle"></span>Relasi unit</span>
                    <button type="button" x-show="selected?.kind === 'user' && canMove" x-cloak @click="openMove()" class="border border-white bg-[var(--oasis-yellow)] px-2 py-1 text-[10px] font-bold text-black">Pindahkan atasan</button>
                    <button type="button" x-show="selected?.kind === 'user' && canMove" x-cloak @click="openUnitMove()" class="border border-white bg-white px-2 py-1 text-[10px] font-bold text-black">Pindahkan unit</button>
                    <button type="button" x-show="selected?.kind === 'user' && canMove" x-cloak @click="requestRemoveSelected()" class="border border-white bg-[#c0392b] px-2 py-1 text-[10px] font-bold text-white">Keluarkan dari struktur</button>
                    <span x-show="selected" x-cloak class="border border-gray-500 px-2 py-1 normal-case text-gray-200"><span class="text-gray-400">Terpilih:</span> <span x-text="selected?.name"></span></span>
                    <span class="border border-gray-500 px-2 py-1 text-gray-200" aria-label="Zoom" x-text="`${Math.round(scale * 100)}%`"></span>
                </span>
            </div>
            <div x-ref="canvas" tabindex="0" class="org-canvas relative min-h-0 flex-1 overflow-hidden cursor-grab outline-none active:cursor-grabbing" @pointerdown="startPan($event)" @pointermove="handlePointerMove($event)" @pointerup="endPointerInteraction($event)" @pointercancel="cancelConnection()" @pointerleave="endPointerInteraction($event)" @wheel.prevent="zoom($event)">
                <div x-ref="graph" class="org-graph absolute left-6 top-6 z-10 origin-top-left" :style="graphStyle()">
                    <svg class="pointer-events-none absolute inset-0 z-0 size-full overflow-visible" aria-hidden="true">
                        <path :d="connectorPath('hierarchy')" fill="none" stroke="#111" stroke-width="2" />
                        <path x-show="showUnitLinks" :d="connectorPath('unit')" fill="none" stroke="#b3bd95" stroke-width="2" stroke-dasharray="6 5" />
                        <path x-show="activeConnection" :d="connectionPreviewPath()" fill="none" stroke="var(--oasis-yellow)" stroke-width="3" stroke-dasharray="7 5" />
                        <path x-show="activeUnitConnection" :d="unitConnectionPreviewPath()" fill="none" stroke="#8c9ae0" stroke-width="4" stroke-dasharray="8 6" />
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
            <div class="mb-3 flex items-center justify-between gap-3 border-b-2 border-black pb-2"><span class="font-[Helvetica] text-xs font-bold uppercase">Area Unit Organisasi</span><span class="text-[10px] text-gray-600">Tekan Delete untuk mengeluarkan user</span></div>
            <div class="space-y-3">
                <template x-for="zone in zones" :key="`mobile-${zone.id}`">
                    <section class="org-unit-zone" :class="zone.unit_id === null ? 'org-unit-zone-unassigned' : (zone.role === 'Pusat organisasi' ? 'org-unit-zone-central' : 'org-unit-zone-branch')" @dragover.prevent @drop.prevent="dropZone($event, zone.id)">
                        <header class="flex items-start justify-between gap-2 border-b-2 border-black pb-2"><div><p class="text-[10px] font-bold uppercase" x-text="zone.role"></p><h2 class="font-bold" x-text="zone.name"></h2></div><span class="border border-black bg-white px-2 py-1 text-[10px] font-bold" x-text="zone.users.length"></span></header>
                        <div class="mt-2 space-y-2"><template x-for="user in zone.users" :key="`mobile-user-${user.id}`"><button type="button" draggable="true" @dragstart="startZoneDrag($event, user.id)" @click="select(user.id)" @keydown.delete.stop="requestRemoveSelected()" class="block w-full border-2 border-black bg-white p-3 text-left" :class="selected?.id === user.id ? 'ring-4 ring-[var(--oasis-focus)] ring-offset-2' : ''"><strong class="block truncate text-sm" x-text="user.name"></strong><span class="block truncate text-xs text-gray-600" x-text="user.role"></span><span class="mt-1 block text-[10px] text-gray-500" x-text="user.parent_id ? `Atasan: ${nodes.find((node) => String(node.id) === String(user.parent_id))?.name || '-'}` : 'Tanpa atasan'"></span></button></template><p x-show="zone.users.length === 0" class="border-2 border-dashed border-black p-3 text-xs text-gray-600">Drop user di area ini.</p></div>
                    </section>
                </template>
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
            <p class="border-t border-gray-300 pt-3 text-xs text-gray-600">Gunakan Area Unit untuk memindahkan pengguna. Diagram hanya untuk melihat garis hierarchy.</p>
            <button type="button" x-show="context?.kind === 'user' && canMove" x-cloak @click="openMove()" class="mt-3 min-h-11 w-full border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-[Helvetica] text-xs font-bold uppercase">Pindahkan garis pelaporan</button>
            <button type="button" x-show="context?.kind === 'user' && canMove" x-cloak @click="requestRemoveSelected()" class="mt-2 min-h-11 w-full border-2 border-black bg-[#c0392b] px-3 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">Keluarkan dari struktur</button>
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

    <div x-show="removeConfirmation" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="organization-remove-title">
        <div class="w-full max-w-md border-2 border-black bg-white p-5">
            <h2 id="organization-remove-title" class="text-xl font-bold">Keluarkan dari struktur?</h2>
            <p class="mt-3 text-sm">Pengguna <strong x-text="selected?.name"></strong> akan dikeluarkan dari Pusat/Cabang dan garis atasannya.</p>
            <p class="mt-2 text-xs text-gray-600">Akun, akses cabang, proyek, dan riwayat pengguna tetap dipertahankan. Ini bukan penghapusan akun permanen.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" @click="removeConfirmation = false" class="border-2 border-black bg-white px-3 py-2 font-bold">Batal</button>
                <button type="button" @click="commitRemove()" class="border-2 border-black bg-[#c0392b] px-3 py-2 font-bold text-white" :disabled="saving" x-text="saving ? 'Memproses...' : 'Keluarkan'"></button>
            </div>
        </div>
    </div>

    <div x-show="unitConfirmation" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="organization-unit-confirm-title">
        <div class="w-full max-w-md border-2 border-black bg-white p-5">
            <h2 id="organization-unit-confirm-title" class="text-xl font-bold">Konfirmasi unit organisasi</h2>
            <p class="mt-3 text-sm">Pindahkan <strong x-text="unitMoveTarget?.user?.name || selected?.name"></strong> ke:</p>
            <select x-model="unitMoveTarget.unitId" class="mt-3 w-full border-2 border-black bg-white px-3 py-2 text-sm">
                <option :value="null">Pilih Pusat atau Cabang</option>
                <template x-for="unit in unitTargets" :key="unit.id"><option :value="unit.unit_id" x-text="unit.name"></option></template>
            </select>
            <p class="mt-3 text-xs text-gray-600">Cabang baru menjadi primary branch. Pindah ke Pusat tidak menghapus akses cabang dan proyek yang sudah ada.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" @click="unitConfirmation = false; unitMoveTarget = null" class="border-2 border-black bg-white px-3 py-2 font-bold">Batal</button>
                <button type="button" @click="commitUnitMove()" class="border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-bold" :disabled="saving || !unitMoveTarget?.unitId">Pindahkan</button>
            </div>
        </div>
    </div>
</div>
@endsection
