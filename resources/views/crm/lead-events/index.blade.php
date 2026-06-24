@extends('layouts.crm')

@section('title', 'Daftar Event - Oasis CRM')

@section('content')
    <div class="bg-[#e6915d] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Daftar Event</h1>
    </div>

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
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <a href="{{ route('lead-events.export', request()->only(['branch_id', 'project_name'])) }}" class="bg-white text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    ↓ Export XLSX
                </a>
                <a href="{{ route('lead-events.create') }}" class="bg-[#e6915d] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4854f]">
                    + Event Baru
                </a>
            </div>
        </form>
    </div>

    <div class="border-2 border-black bg-white overflow-x-auto">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Event ID</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Proyek</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Sumber Lead</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Tgl Mulai</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Tgl Selesai</th>
                    <th class="px-3 py-2 text-right font-[Helvetica] font-bold text-xs uppercase">Anggaran</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Status</th>
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
                    <td class="px-3 py-2">{{ $event->end_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">{{ $event->total_budget ? 'Rp' . number_format($event->total_budget, 0, ',', '.') : '—' }}</td>
                    <td class="px-3 py-2 text-center">
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
<div id="bulk-bar" class="fixed bottom-4 left-4 z-50 bg-white border-2 border-black shadow-lg hidden">
    <div class="flex items-center gap-3 px-4 py-3">
        <span class="text-sm font-[Helvetica] font-bold"><span id="bulk-count">0</span> data terpilih</span>
        <button onclick="bulkDelete()" class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#c0392b] cursor-pointer">
            Hapus Terpilih
        </button>
    </div>
</div>

<form id="bulk-form" method="POST" action="{{ route('lead-events.bulk-destroy') }}" class="hidden">
    @csrf
    <input type="hidden" name="selected_ids" id="bulk-ids">
    @foreach(request()->only(['branch_id', 'project_name']) as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
</form>

<script>
let selected = new Set();
document.getElementById('select-all')?.addEventListener('change', function() {
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.checked = this.checked;
        this.checked ? selected.add(cb.value) : selected.delete(cb.value);
    });
    toggleBulkBar();
});
document.querySelectorAll('.row-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        this.checked ? selected.add(this.value) : selected.delete(this.value);
        toggleBulkBar();
    });
});
function toggleBulkBar() {
    const bar = document.getElementById('bulk-bar');
    const count = selected.size;
    document.getElementById('bulk-count').textContent = count;
    bar.style.display = count > 0 ? 'block' : 'none';
}
function bulkDelete() {
    const count = selected.size;
    if (!count) return;
    if (!confirm('Hapus ' + count + ' data terpilih?')) return;
    document.getElementById('bulk-ids').value = Array.from(selected).join(',');
    document.getElementById('bulk-form').submit();
}
</script>
@endsection
