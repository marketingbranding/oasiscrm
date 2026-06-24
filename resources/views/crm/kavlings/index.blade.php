@extends('layouts.crm')

@section('title', 'Kavling - ' . $project->project_name . ' - Oasis CRM')

@section('content')
    <div class="bg-[#5d8e8e] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Kavling — {{ $project->project_name }}</h1>
    </div>

    <div class="flex justify-end mb-4 gap-2">
        <a href="{{ route('projects.index') }}" class="bg-white text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
            ← Kembali ke Proyek
        </a>
        <a href="{{ route('kavlings.bulk-import', ['project' => $project->id]) }}" class="bg-[#5d8e8e] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#4a7a7a]">
            + Import Kavling
        </a>
    </div>

    <div class="border-2 border-black bg-white overflow-x-auto">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Kode Kavling</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Nama Lengkap</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($kavlings as $k)
                <tr class="hover:bg-gray-50">
                    <td class="w-10 px-3 py-2 text-center"><input type="checkbox" class="row-checkbox cursor-pointer" value="{{ $k->id }}"></td>
                    <td class="px-3 py-2 font-bold">{{ $k->kavling_code }}</td>
                    <td class="px-3 py-2">{{ $k->name }}</td>
                    <td class="px-3 py-2 text-center">
                        <form method="POST" action="{{ route('kavlings.destroy', $k->id) }}"
                              onsubmit="return confirm('Hapus kavling {{ $k->kavling_code }}?')" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-4 py-8 text-center text-sm">
                        Belum ada kavling untuk proyek ini.
                        <a href="{{ route('kavlings.bulk-import', ['project' => $project->id]) }}" class="underline font-bold hover:text-[#5d8e8e]">Import kavling</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-2 text-xs text-gray-500">Total: {{ $kavlings->count() }} kavling</div>

<div id="bulk-bar" class="fixed bottom-4 left-4 z-50 bg-white border-2 border-black shadow-lg hidden">
    <div class="flex items-center gap-3 px-4 py-3">
        <span class="text-sm font-[Helvetica] font-bold"><span id="bulk-count">0</span> kavling terpilih</span>
        <button onclick="bulkDelete()" class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#c0392b] cursor-pointer">
            Hapus Terpilih
        </button>
    </div>
</div>

<form id="bulk-form" method="POST" action="{{ route('kavlings.bulk-destroy') }}" class="hidden">
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
    if (!confirm('Hapus ' + count + ' kavling terpilih?')) return;
    document.getElementById('bulk-ids').value = Array.from(selected).join(',');
    document.getElementById('bulk-form').submit();
}
</script>
@endsection
