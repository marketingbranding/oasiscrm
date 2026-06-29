@extends('layouts.crm')

@section('title', 'Daftar Event - Oasis CRM')

@section('content')
    <x-crm.page-header color="#e6915d" title="Daftar Event" />

    @php $sourceTabUrl = request()->fullUrlWithQuery(['tab' => 'sources']); @endphp

    <div x-data="{ activeTab: '{{ $tab }}' }">
        {{-- Tab Bar --}}
        <div class="border-2 border-black mb-6 bg-white">
            <div class="flex">
                <button @click="activeTab = 'events'"
                        :class="activeTab === 'events' ? 'bg-black text-white' : 'bg-white text-black hover:bg-gray-100'"
                        class="px-5 py-2 text-sm font-[Helvetica] font-bold border-r border-black transition-colors">
                    Daftar Event
                </button>
                <button @click="activeTab = 'sources'"
                        :class="activeTab === 'sources' ? 'bg-black text-white' : 'bg-white text-black hover:bg-gray-100'"
                        class="px-5 py-2 text-sm font-[Helvetica] font-bold border-r border-black transition-colors">
                    Sumber Lead
                </button>
            </div>
        </div>

        {{-- ==================== EVENTS TAB ==================== --}}
        <div x-show="activeTab === 'events'">
            <div class="bg-white border-2 border-black p-3 mb-6">
                <form method="GET" action="{{ route('lead-events.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
                    <div class="flex items-center gap-3 flex-wrap">
                    @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
                    <label class="font-[Helvetica] font-bold text-xs uppercase">Cabang:</label>
                    <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                        <option value="">— Semua Cabang —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @endif

                    <label class="font-[Helvetica] font-bold text-xs uppercase">Proyek:</label>
                    <select name="project_name" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                        <option value="">— Semua Proyek —</option>
                        @foreach($projects as $p)
                            <option value="{{ $p->project_name }}" {{ $selectedProjectName === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                        @endforeach
                    </select>
                        <label class="font-[Helvetica] font-bold text-xs uppercase">Cari:</label>
                        <input name="search" value="{{ request('search') }}"
                               placeholder="Proyek, Sumber, Lokasi..."
                               class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none"
                               onkeydown="if(event.key==='Enter') this.form.submit()">
                    </div>

                    <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

                    <div class="flex items-center gap-2 ml-auto">
                        <x-crm.export-import export-route="lead-events.export" import-route="lead-events.import" :params="request()->only(['branch_id', 'project_name'])" />
                        <a href="{{ route('lead-events.create') }}" class="bg-[#e6915d] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4854f]">
                            + Event Baru
                        </a>
                    </div>
                </form>
            </div>

            <div class="table-scroll border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
                        <table class="min-w-full text-sm font-['Times_New_Roman']">
                    <thead>
                        <tr class="bg-black text-white">
                            <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                            <x-crm.sortable-th field="event_id" route="lead-events.index" label="Event ID" />
                            <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                            <x-crm.sortable-th field="project_name" route="lead-events.index" label="Proyek" />
                            <x-crm.sortable-th field="lead_source" route="lead-events.index" label="Sumber Lead" />
                            <x-crm.sortable-th field="start_date" route="lead-events.index" label="Tgl Mulai" />
                            <x-crm.sortable-th field="end_date" route="lead-events.index" label="Tgl Selesai" classes="hidden lg:table-cell" />
                            <x-crm.sortable-th field="total_budget" route="lead-events.index" label="Anggaran" align="right" classes="hidden lg:table-cell" />
                            <x-crm.sortable-th field="status" route="lead-events.index" label="Status" align="center" classes="hidden lg:table-cell" />
                            <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black">
                        @forelse($events as $event)
                        <tr class="hover:bg-gray-50">
                            <td class="w-10 px-3 py-2 text-center"><input type="checkbox" class="row-checkbox cursor-pointer" value="{{ $event->id }}"></td>
                            <td class="px-3 py-2 font-bold">{{ $event->event_id ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $event->branch->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $event->project_name }}</td>
                            <td class="px-3 py-2">{{ $event->lead_source }}</td>
                            <td class="px-3 py-2">{{ $event->start_date->format('d M Y') }}</td>
                            <td class="px-3 py-2 hidden lg:table-cell">{{ $event->end_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-2 text-right hidden lg:table-cell">{{ $event->total_budget ? 'Rp' . number_format($event->total_budget, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-2 text-center hidden lg:table-cell">
                                <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $event->status === 'selesai' ? 'bg-[#b3bd95]' : 'bg-[#9ab6c8]' }}">
                                    {{ strtoupper($event->status) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <a href="{{ route('lead-events.edit', $event->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e6915d]">Edit</a>
                                    <form method="POST" action="{{ route('lead-events.destroy', ['lead_event' => $event->id]) }}"
                                          onsubmit="return confirm('Hapus event ini?')" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                                        <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                                        <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="px-4 py-8 text-center text-sm">Belum ada event.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-crm.pagination :collection="$events" :per-page="$perPage" />

            <x-crm.bulk-bar
                destroy-route="{{ route('lead-events.bulk-destroy') }}"
                update-route="{{ route('lead-events.bulk-update') }}"
                :status-options="['berlangsung' => 'Berlangsung', 'selesai' => 'Selesai']"
                status-label="Event"
                accent-color="#e6915d"
                :params="request()->only(['branch_id', 'project_name'])" />
        </div>

        {{-- ==================== SOURCES TAB ==================== --}}
        <div x-show="activeTab === 'sources'" x-cloak>
            <div class="bg-[#5d8e8e] border-2 border-black px-4 py-2 mb-6 flex items-center justify-between">
                <h2 class="font-['Arial_Black'] font-black text-xl uppercase">Sumber Lead</h2>
            </div>

            {{-- Add form --}}
            <div class="bg-white border-2 border-black p-3 mb-6">
                <form method="POST" action="{{ route('lead-sources.store') }}" class="flex items-center gap-3">
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ $sourceTabUrl }}">
                    <input type="text" name="name" required placeholder="Nama sumber baru..."
                           class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none w-64">
                    <button type="submit"
                            class="bg-[#e6915d] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4854f]">
                        + Tambah
                    </button>
                </form>
            </div>

            {{-- Sources table --}}
            <div x-data="{ selected: new Set() }" class="border-2 border-black bg-white">
                <div class="overflow-auto max-h-[calc(100vh-12rem)]">
                    <table class="min-w-full text-sm font-['Times_New_Roman']">
                        <thead>
                            <tr class="bg-black text-white">
                                <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">
                                    <input type="checkbox" @change="document.querySelectorAll('.src-checkbox').forEach(cb => { cb.checked = $event.target.checked; $event.target.checked ? selected.add(cb.value) : selected.delete(cb.value); })" class="cursor-pointer">
                                </th>
                                <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Nama</th>
                                <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase w-24">Status</th>
                                <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase w-36">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black">
                            @forelse($sources as $src)
                            <tr x-data="{ editing: false, originalName: @json($src->name), editName: @json($src->name) }" class="hover:bg-gray-50">
                                <td class="w-10 px-3 py-2 text-center">
                                    <input type="checkbox" class="src-checkbox cursor-pointer" value="{{ $src->id }}"
                                           @change="$event.target.checked ? selected.add($event.target.value) : selected.delete($event.target.value)">
                                </td>
                                <td class="px-3 py-2">
                                    <span class="font-bold" x-show="!editing">{{ $src->name }}</span>
                                    <form x-show="editing" method="POST" action="{{ route('lead-sources.update', $src->id) }}" class="flex items-center gap-1">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="redirect_to" value="{{ $sourceTabUrl }}">
                                        <input type="text" name="name" x-model="editName" required
                                               class="border-2 border-black px-2 py-0.5 text-sm font-['Times_New_Roman'] w-40">
                                        <button type="submit"
                                                class="border border-black px-2 py-0.5 text-xs font-[Helvetica] font-bold bg-[#b3bd95] hover:bg-[#9eaa7a]">
                                            Simpan
                                        </button>
                                        <button type="button" @click="editing = false; editName = originalName"
                                                class="border border-black px-2 py-0.5 text-xs font-[Helvetica] font-bold bg-gray-100 hover:bg-gray-200">
                                            Batal
                                        </button>
                                    </form>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <form method="POST" action="{{ route('lead-sources.toggle-active', $src->id) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="redirect_to" value="{{ $sourceTabUrl }}">
                                        <button type="submit"
                                            class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black cursor-pointer {{ $src->is_active ? 'bg-[#b3bd95] hover:bg-[#9eaa7a]' : 'bg-gray-200 hover:bg-gray-300' }}">
                                            {{ $src->is_active ? 'AKTIF' : 'NONAKTIF' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button @click="editing = true"
                                                class="text-xs font-[Helvetica] font-bold underline hover:text-[#e6915d]">
                                            Edit
                                        </button>
                                        <form method="POST" action="{{ route('lead-sources.destroy', $src->id) }}"
                                              onsubmit="return confirm('Hapus sumber lead ini?')" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="redirect_to" value="{{ $sourceTabUrl }}">
                                            <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm">Belum ada sumber lead.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <form id="source-bulk-form" method="POST" action="{{ route('lead-sources.bulk-destroy') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ $sourceTabUrl }}">
                    <input type="hidden" name="selected_ids" id="source-bulk-ids">
                </form>

                <template x-if="selected.size > 0">
                    <div class="border-t-2 border-black px-4 py-3 flex items-center gap-3 bg-gray-50">
                        <span class="text-sm font-[Helvetica] font-bold" x-text="selected.size + ' data terpilih'"></span>
                        <div class="h-6 w-px bg-black mx-1"></div>
                        <button @click="if (confirm('Hapus ' + selected.size + ' data terpilih?')) { document.getElementById('source-bulk-ids').value = Array.from(selected).join(','); document.getElementById('source-bulk-form').submit(); }"
                                class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#c0392b] cursor-pointer">
                            Hapus Terpilih
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>

<style>
.table-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 10;
    background-color: black;
    color: white;
}
</style>
@endsection