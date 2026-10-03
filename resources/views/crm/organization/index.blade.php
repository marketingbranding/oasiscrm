@extends('layouts.crm')

@section('title', 'Struktur Organisasi')

@section('content')
<div x-data="organizationWorkspace(@js($flatNodes), @js(route('organization.move', ['user' => '__USER__'])))" class="space-y-5 p-4 md:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b-2 border-black pb-4">
        <div>
            <p class="font-[Helvetica] text-xs font-bold uppercase tracking-[0.16em] text-gray-600">Administrasi / Organisasi</p>
            <h1 class="mt-1 font-['Arial_Black'] text-2xl uppercase tracking-tight md:text-3xl">Struktur Organisasi</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-700">Atur garis pelaporan tanpa mengubah primary role atau akses cabang dan proyek.</p>
        </div>
        <div class="flex flex-wrap gap-2 font-[Helvetica] text-sm">
            <a href="{{ route('admin-users.index') }}" class="border-2 border-black bg-white px-3 py-2 font-bold hover:bg-[var(--oasis-yellow)]">Atur Pengguna</a>
            <span class="border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-bold">Role != Parent</span>
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
        <label class="flex min-w-64 flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">
            Cari pengguna
            <input type="search" x-model.debounce.150ms="search" placeholder="Nama pengguna..." class="border-2 border-black px-3 py-2 text-sm font-normal">
        </label>
        <p class="ml-auto font-[Helvetica] text-xs text-gray-600">Drag node untuk memindahkan. Gunakan panel detail untuk alternatif keyboard.</p>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="hidden min-h-[32rem] overflow-hidden border-2 border-black bg-[#f5f0df] md:block" aria-label="Kanvas struktur organisasi"
                 @pointerdown="startPan($event)" @pointermove="pan($event)" @pointerup="endPan()" @pointerleave="endPan()" @wheel.prevent="zoom($event)">
            <div class="flex items-center justify-between border-b-2 border-black bg-black px-3 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">
                <span>Kanvas Organisasi</span><span x-text="`${Math.round(scale * 100)}%`"></span>
            </div>
            <div class="relative h-[29rem] overflow-hidden cursor-grab active:cursor-grabbing">
                <div class="absolute left-1/2 top-8 min-w-max origin-top-left" :style="`transform: translate(${offsetX}px, ${offsetY}px) scale(${scale});`">
                    <div class="flex items-start gap-6">
                        @foreach($nodes as $node)
                            @include('crm.organization._node', ['node' => $node])
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section class="border-2 border-black bg-white p-3 md:hidden" aria-label="Daftar hierarki organisasi">
            <div class="mb-3 border-b-2 border-black pb-2 font-[Helvetica] text-xs font-bold uppercase">Daftar Hierarki</div>
            <div class="space-y-2">
                @foreach($nodes as $node)
                    @include('crm.organization._mobile-node', ['node' => $node, 'depth' => 0])
                @endforeach
            </div>
        </section>

        <aside x-show="selected" x-cloak class="border-2 border-black bg-white p-4" aria-label="Detail pengguna terpilih">
            <div class="flex items-start justify-between gap-3 border-b-2 border-black pb-3">
                <div><p class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Detail Node</p><h2 class="mt-1 text-xl font-bold" x-text="selected?.name"></h2></div>
                <button type="button" class="border-2 border-black px-2 py-1 font-[Helvetica] text-xs font-bold" @click="selected = null" aria-label="Tutup detail">X</button>
            </div>
            <dl class="grid gap-2 py-4 text-sm">
                <div><dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Primary role</dt><dd x-text="selected?.role"></dd></div>
                <div><dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Cabang</dt><dd x-text="selected?.branch || 'Tidak ditentukan'"></dd></div>
                <div><dt class="font-[Helvetica] text-xs font-bold uppercase text-gray-600">Direct reports</dt><dd x-text="selected?.direct_reports"></dd></div>
            </dl>
            <div class="border-t-2 border-black pt-4">
                <label class="flex flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">Pindahkan ke...
                    <select x-model="newParentId" class="mt-1 border-2 border-black bg-white px-3 py-2 text-sm font-normal">
                        <option value="">Tanpa parent</option>
                        <template x-for="node in moveTargets" :key="node.id"><option :value="node.id" x-text="`${node.name} - ${node.role}`"></option></template>
                    </select>
                </label>
                <button type="button" @click="openConfirmation()" class="mt-3 w-full border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-[Helvetica] text-sm font-bold uppercase">Pindahkan</button>
            </div>
        </aside>
    </div>

    <div x-show="confirmation" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="organization-confirm-title">
        <div class="w-full max-w-md border-2 border-black bg-white p-5">
            <h2 id="organization-confirm-title" class="text-xl font-bold">Konfirmasi perpindahan</h2>
            <p class="mt-3 text-sm">Pindahkan <strong x-text="selected?.name"></strong> ke <strong x-text="parentName"></strong>?</p>
            <p class="mt-1 text-xs text-gray-600">Peran pengguna tidak berubah. Berlaku hari ini.</p>
            <div class="mt-5 flex justify-end gap-2 font-[Helvetica] text-sm">
                <button type="button" @click="confirmation = false" class="border-2 border-black bg-white px-3 py-2 font-bold">Batal</button>
                <button type="button" @click="commitMove()" class="border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-bold" :disabled="saving" x-text="saving ? 'Menyimpan...' : 'Pindahkan'"></button>
            </div>
        </div>
    </div>
</div>
@endsection
