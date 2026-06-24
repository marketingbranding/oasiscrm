@extends('layouts.crm')

@section('title', 'Proyek - Oasis CRM')

@section('content')
    <div class="bg-[#5d8e8e] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Manajemen Proyek</h1>
    </div>

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('projects.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <div class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <a href="{{ route('projects.create') }}" class="bg-[#5d8e8e] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#4a7a7a]">
                    + Proyek Baru
                </a>
            </div>
        </form>
    </div>

    <div class="border-2 border-black bg-white overflow-x-auto">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'project_name';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'project_name', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'project_name', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('projects.index', $linkParams) }}" class="hover:underline text-white">
                            Proyek
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'kavlings_count';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'kavlings_count', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'kavlings_count', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('projects.index', $linkParams) }}" class="hover:underline text-white">
                            Kavling
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'is_active';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'is_active', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'is_active', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('projects.index', $linkParams) }}" class="hover:underline text-white">
                            Status
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($projects as $p)
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 font-bold">{{ $p->project_name }}</td>
                    <td class="px-3 py-2">{{ $p->branch->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-center font-bold">{{ $p->kavlings_count }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $p->is_active ? 'bg-[#b3bd95]' : 'bg-gray-200' }}">
                            {{ $p->is_active ? 'AKTIF' : 'NONAKTIF' }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <a href="{{ route('kavlings.index', ['project' => $p->id]) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#5d8e8e]">Kavling</a>
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
                    <td colspan="5" class="px-4 py-8 text-center text-sm">Belum ada proyek.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
