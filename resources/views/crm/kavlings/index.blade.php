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
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Kode Kavling</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Nama Lengkap</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($kavlings as $k)
                <tr class="hover:bg-gray-50">
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
                    <td colspan="3" class="px-4 py-8 text-center text-sm">
                        Belum ada kavling untuk proyek ini.
                        <a href="{{ route('kavlings.bulk-import', ['project' => $project->id]) }}" class="underline font-bold hover:text-[#5d8e8e]">Import kavling</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-2 text-xs text-gray-500">Total: {{ $kavlings->count() }} kavling</div>
@endsection
