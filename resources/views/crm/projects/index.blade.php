@extends('layouts.crm')

@section('title', 'Proyek - Oasis CRM')

@section('content')
    <div class="bg-[#5d8e8e] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Manajemen Proyek</h1>
    </div>

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('projects.index') }}" class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="flex justify-end mb-4">
        <a href="{{ route('projects.create') }}" class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-red-600">
            + Proyek Baru
        </a>
    </div>

    <div class="border-2 border-black bg-white overflow-x-auto">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Proyek</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Sumber</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Kategori</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Status</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($projects as $p)
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 font-bold">{{ $p->project_name }}</td>
                    <td class="px-3 py-2">{{ $p->lead_source ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $p->category ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $p->branch->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $p->is_active ? 'bg-[#b3bd95]' : 'bg-gray-200' }}">
                            {{ $p->is_active ? 'AKTIF' : 'NONAKTIF' }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <a href="{{ route('projects.edit', $p->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#5d8e8e]">Edit</a>
                            <form method="POST" action="{{ route('projects.destroy', ['project' => $p->id]) }}"
                                  onsubmit="return confirm('Hapus proyek ini?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm">Belum ada proyek.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
