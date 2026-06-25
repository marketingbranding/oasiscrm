@extends('layouts.crm')

@section('title', 'Sumber Lead - Oasis CRM')

@section('content')
    <div class="bg-[#5d8e8e] border-2 border-black px-4 py-2 mb-6 flex items-center justify-between">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Sumber Lead</h1>
        <a href="{{ route('lead-sources.create') }}" class="bg-[#5d8e8e] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#4a7a7a]">
            + Tambah Sumber
        </a>
    </div>

    <div class="table-scroll border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Nama</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Status</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($sources as $src)
                <tr class="hover:bg-gray-50">
                    <td class="w-10 px-3 py-2 text-center"><input type="checkbox" class="row-checkbox cursor-pointer" value="{{ $src->id }}"></td>
                    <td class="px-3 py-2 font-bold">{{ $src->name }}</td>
                    <td class="px-3 py-2 text-center">
                        <form method="POST" action="{{ route('lead-sources.toggle-active', $src->id) }}" class="inline">
                            @csrf
                            <button type="submit"
                                class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black cursor-pointer {{ $src->is_active ? 'bg-[#b3bd95] hover:bg-[#9eaa7a]' : 'bg-gray-200 hover:bg-gray-300' }}"
                                title="{{ $src->is_active ? 'Klik untuk nonaktifkan' : 'Klik untuk aktifkan' }}">
                                {{ $src->is_active ? 'AKTIF' : 'NONAKTIF' }}
                            </button>
                        </form>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <a href="{{ route('lead-sources.edit', $src->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#5d8e8e]">Edit</a>
                            <form method="POST" action="{{ route('lead-sources.destroy', $src->id) }}"
                                  onsubmit="return confirm('Hapus sumber lead ini?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
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

<div id="bulk-bar" class="fixed bottom-4 left-4 z-50 bg-white border-2 border-black shadow-lg hidden">
    <div class="flex items-center gap-3 px-4 py-3">
        <span class="text-sm font-[Helvetica] font-bold"><span id="bulk-count">0</span> data terpilih</span>
        <div class="h-6 w-px bg-black mx-1"></div>
        <button onclick="bulkDelete()" class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#c0392b] cursor-pointer">
            Hapus Terpilih
        </button>
    </div>
</div>

<form id="bulk-form" method="POST" action="{{ route('lead-sources.bulk-destroy') }}" class="hidden">
    @csrf
    <input type="hidden" name="selected_ids" id="bulk-ids">
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
