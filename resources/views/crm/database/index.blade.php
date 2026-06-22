@extends('layouts.crm')

@section('title', 'Database - Oasis CRM')

@section('content')
    <div class="bg-[#d77a7a] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Database</h1>
    </div>

    @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('database.index') }}" class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->code }})</option>
                @endforeach
            </select>
        </form>
    </div>
    @elseif(isset($branchCode))
    <div class="bg-[#fcc20f] border-2 border-black px-4 py-2 mb-4">
        <span class="font-['Arial_Black'] font-black text-lg uppercase">Cabang: {{ $branchCode }}</span>
    </div>
    @endif

    @if(isset($error))
    <div class="bg-[#d77a7a] border-2 border-black px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm">
        {{ $error }}
    </div>
    @endif

    @if(isset($branchCode) && $selectedBranch && $selectedBranch->sheet_id)
    @php
        $sheetId = $selectedBranch->sheet_id;
        $webAppUrl = config('services.google_script.webhook_url');
        $databaseUrl = $webAppUrl . '?sheet_id=' . urlencode($sheetId) . '&cabang_name=' . urlencode($selectedBranch->name);
    @endphp
    <div class="border-2 border-black mb-4">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase flex items-center justify-between">
            <span>Database — {{ $branchCode }}</span>
            <a href="https://docs.google.com/spreadsheets/d/{{ $sheetId }}/edit" target="_blank"
               class="text-xs text-gray-300 hover:text-white underline">Buka Google Sheet</a>
        </div>
        <div class="bg-white p-8 text-center">
            <p class="font-['Times_New_Roman'] text-sm mb-4">
                Buka aplikasi database <strong>{{ $selectedBranch->name }}</strong> untuk mengelola data.
            </p>
            <a href="{{ $databaseUrl }}" target="_blank"
               class="inline-block bg-black text-white px-6 py-3 font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800 uppercase text-sm">
                Buka Database {{ $branchCode }}
            </a>
        </div>
    </div>
    @elseif(isset($branchCode))
    <div class="border-2 border-black">
        <div class="bg-white px-6 py-8 text-center">
            <p class="font-['Times_New_Roman'] text-sm">
                @if(Auth::user()->canViewAllBranches())
                    Silakan pilih cabang terlebih dahulu untuk mengakses database.
                @else
                    Database branch belum tersedia. Hubungi superadmin.
                @endif
            </p>
        </div>
    </div>
    @endif
@endsection
