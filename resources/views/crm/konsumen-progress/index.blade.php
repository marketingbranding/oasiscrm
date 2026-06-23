@extends('layouts.crm')

@section('title', 'Konsumen Progress - Oasis CRM')

@section('content')
    <div class="bg-[#5d8e8e] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Konsumen Progress</h1>
    </div>

    @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('konsumen-progress.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->code }})</option>
                @endforeach
            </select>
        </form>
    </div>
    @elseif($selectedBranch)
    <div class="bg-[#fcc20f] border-2 border-black px-4 py-2 mb-4">
        <span class="font-['Arial_Black'] font-black text-lg uppercase">Cabang: {{ $selectedBranch->code }}</span>
    </div>
    @endif

    @if(!empty($errors))
    <div class="bg-[#d77a7a] border-2 border-black px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm">
        <strong class="font-bold">Gagal memuat beberapa stage:</strong>
        <ul class="list-disc pl-5 mt-1">
            @foreach($errors as $err)
            <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @php
        $stages = [
            'bi_checking' => ['label' => 'BI Checking', 'color' => '#9ab6c8'],
            'PSJB' => ['label' => 'PSJB', 'color' => '#e6915d'],
            'pemberkasan' => ['label' => 'Pemberkasan', 'color' => '#b3bd95'],
            'proses_bank' => ['label' => 'Proses Bank', 'color' => '#f1c40f'],
            'ppjb_dev' => ['label' => 'PPJB Dev', 'color' => '#8c9ae0'],
            'akad' => ['label' => 'Akad', 'color' => '#5d8e8e'],
            'bast' => ['label' => 'BAST', 'color' => '#c0d4a7'],
        ];
    @endphp

    @if($selectedBranch && $selectedBranch->sheet_id)
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-7 gap-4">
        @foreach($stages as $key => $cfg)
        @php $items = $pipeline[$key] ?? []; @endphp
        <div class="border-2 border-black bg-white flex flex-col">
            <div class="px-3 py-2 font-[Helvetica] font-bold text-xs uppercase flex items-center justify-between"
                 style="background-color: {{ $cfg['color'] }}; color: {{ in_array($key, ['proses_bank', 'ppjb_dev', 'akad']) ? 'white' : 'black' }};">
                <span>{{ $cfg['label'] }}</span>
                <span class="bg-white text-black px-2 py-0.5 text-[10px] border border-black">{{ count($items) }}</span>
            </div>
            <div class="divide-y divide-black overflow-y-auto" style="max-height: 500px;">
                @forelse($items as $item)
                <div class="px-3 py-2 hover:bg-gray-50">
                    <div class="font-['Times_New_Roman'] font-bold text-sm">{{ $item['nama'] }}</div>
                    <div class="font-['Helvetica'] text-[11px] text-gray-600 mt-0.5">{{ $item['kavling'] }}</div>
                </div>
                @empty
                <div class="px-3 py-6 text-center text-xs text-gray-400 font-['Times_New_Roman']">—</div>
                @endforelse
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="border-2 border-black bg-white px-6 py-8 text-center">
        <p class="font-['Times_New_Roman'] text-sm">
            @if(Auth::user()->canViewAllBranches())
                Silakan pilih cabang terlebih dahulu.
            @else
                Database branch belum tersedia. Hubungi superadmin.
            @endif
        </p>
    </div>
    @endif
@endsection
