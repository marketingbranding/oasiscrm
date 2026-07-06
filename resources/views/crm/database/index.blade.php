@extends('layouts.crm')

@section('title', 'Database - Oasis CRM')

@section('content')
    <div class="bg-[#d77a7a] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Database</h1>
    </div>

    @if(Auth::user()->isSuperadmin() && isset($branches) && $branches->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('database.index') }}" class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->code }})</option>
                @endforeach
            </select>
            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>
            <div class="flex items-center gap-2 ml-auto">
                <button type="submit" form="database-sync-form" class="bg-white text-black px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    Sync Sekarang
                </button>
            </div>
        </form>
    </div>
    @elseif($selectedBranch)
    <div class="bg-[#fcc20f] border-2 border-black px-4 py-2 mb-4 flex items-center gap-3">
        <span class="font-['Arial_Black'] font-black text-lg uppercase">Cabang: {{ $selectedBranch->code }}</span>
        <button type="submit" form="database-sync-form" class="bg-white text-black px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100 ml-auto">
            Sync Sekarang
        </button>
    </div>
    @endif

    @if($selectedBranch)
    <form id="database-sync-form" method="POST" action="{{ route('database.sync') }}" class="hidden">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
    </form>

    <div class="border-2 border-black bg-white px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
        <div>
            <strong class="font-bold">Status Sync:</strong>
            @if($syncStatus?->status === 'success')
                <span>Terakhir sync {{ $syncStatus->finished_at?->format('d M Y H:i') }}</span>
            @elseif($syncStatus?->status === 'failed')
                <span class="text-[#c0392b]">Sync terakhir gagal: {{ $syncStatus->message }}</span>
            @elseif($syncStatus?->status === 'running')
                <span>Sedang sync...</span>
            @else
                <span>Belum pernah sync. Klik Sync Sekarang.</span>
            @endif
        </div>
        @if($isStale)
            <span class="inline-block bg-[#fcc20f] border-2 border-black px-2 py-1 font-[Helvetica] text-[10px] font-bold uppercase">Data perlu diperbarui</span>
        @endif
    </div>
    @endif

    @if($selectedBranch && !empty($sheetNames))
    <div x-data="{ tab: '{{ $sheetNames[0] ?? '' }}', editing: null, adding: false }">
        <style>
            [x-cloak] { display: none !important; }
            .tab-wrap { overflow-x:auto; overflow-y:hidden; white-space:nowrap; max-width:100%; border-bottom:2px solid #000; margin-bottom:12px; scroll-behavior:smooth; }
            .tab-wrap::-webkit-scrollbar { height:4px; }
            .tab-wrap::-webkit-scrollbar-thumb { background:#d77a7a; border-radius:2px; }
            .tab-btn { display:inline-block; padding:6px 14px; border:2px solid #000; border-bottom:none;
                        font-family:Helvetica,sans-serif; font-weight:700; font-size:11px;
                        text-transform:uppercase; cursor:pointer; background:#fff; color:#000;
                        white-space:nowrap; margin:0; }
            .tab-btn + .tab-btn { border-left:none; }
            .tab-btn.active { background:#d77a7a; color:#fff; position:relative; }
            .tab-btn.active::after { content:''; position:absolute; bottom:-2px; left:0; right:0; height:2px; background:#d77a7a; z-index:11; }
            .tab-btn:hover:not(.active) { background:#f5f5f5; }
            .db-table { font-size:12px; border-collapse:collapse; width:100%; }
            .db-table th { position:sticky; top:0; z-index:5;
                        border:2px solid #000; background:#000; color:#fff;
                        font-family:Helvetica,sans-serif; font-weight:700; font-size:10px;
                        text-transform:uppercase; padding:6px 8px; white-space:nowrap; text-align:left; }
            .db-table td { border:2px solid #000; padding:4px 8px;
                        font-family:'Times New Roman',serif; }
            .db-table tbody tr:nth-child(even) { background:#f9fafb; }
            .db-table tbody tr:hover { background:#fef3c7; }
        </style>

        <div class="tab-wrap" x-on:wheel="if ($event.currentTarget.scrollWidth > $event.currentTarget.clientWidth) { (function(e){e._sd=(e._sd||0)+$event.deltaY;if(!e._st){e._st=true;requestAnimationFrame(function(){var d=e._sd;e._sd=0;e._st=false;e.scrollLeft+=Math.sign(d)*Math.min(Math.abs(d)*1.5,160)})}}($event.currentTarget)); $event.preventDefault(); }">
            @foreach($sheetNames as $name)
            @php $rowCount = count($records[$name] ?? []); @endphp
            <button @click="tab = '{{ $name }}'"
                    :class="tab === '{{ $name }}' ? 'active' : ''"
                    class="tab-btn">
                {{ $name }} <span :class="tab === '{{ $name }}' ? 'text-white/70' : 'text-gray-500'" style="font-size:9px;font-weight:400;">({{ $rowCount }})</span>
            </button>
            @endforeach
        </div>

        @foreach($sheetNames as $name)
        @php
            $rows = $records[$name] ?? [];
            $sample = $rows[0] ?? null;
            $hasData = $sample !== null;
            $headers = $hasData ? $sample->headers : [];
            $formulaColumns = $hasData ? ($sample->formula_columns ?? []) : [];
            $editableHeaders = array_values(array_filter($headers, fn($h) => !in_array($h, $formulaColumns, true) && !in_array($h, ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'], true)));
        @endphp
        <div x-show="tab === '{{ $name }}'" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
            <div class="mb-3 flex items-center gap-2" style="min-height:32px;">
                @if($hasData)
                <button @click="adding = '{{ $name }}'"
                        class="bg-black text-white px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-black hover:bg-gray-800" style="border-radius:0;">
                    + Tambah Data
                </button>
                @endif
                <a href="https://docs.google.com/spreadsheets/d/{{ $selectedBranch->sheet_id }}" target="_blank"
                   class="bg-white text-black px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-black hover:bg-gray-100" style="border-radius:0;">
                    Buka Google Sheet
                </a>
            </div>

            @if($hasData)
            <div class="overflow-auto border-2 border-black" style="max-height:65vh;">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th style="width:44px;text-align:center;">#</th>
                            @foreach($headers as $h)
                                @if(in_array($h, ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'], true)) @continue @endif
                                <th class="{{ in_array($h, $formulaColumns, true) ? 'formula-col' : '' }}" style="min-width:120px;">
                                    {{ $h }}
                                    @if(in_array($h, $formulaColumns, true))
                                        <span style="font-size:9px;font-weight:400;color:#fcc20f;">[f]</span>
                                    @endif
                                </th>
                            @endforeach
                            <th style="width:100px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $rec)
                        @php $data = $rec->row_data; @endphp
                        <tr>
                            <td style="text-align:center;color:#6b7280;">{{ $rec->row_number }}</td>
                            @foreach($headers as $h)
                                @if(in_array($h, ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'], true)) @continue @endif
                                @php $isFormula = in_array($h, $formulaColumns, true); @endphp
                                <td style="color:{{ $isFormula ? '#9ca3af' : '#000' }};font-style:{{ $isFormula ? 'italic' : 'normal' }};max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $data[$h] ?? '' }}">
                                    {{ $data[$h] ?? '' }}
                                </td>
                            @endforeach
                            <td style="white-space:nowrap;">
                                <button @click="editing = {{ $rec->id }}"
                                        class="font-[Helvetica] font-bold underline" style="font-size:11px;color:#0000ee;margin-right:8px;">Edit</button>
                                <form method="POST" action="{{ route('database.records.destroy', $rec) }}" style="display:inline;" onsubmit="return confirm('Hapus data ini?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="font-[Helvetica] font-bold underline" style="font-size:11px;color:#c0392b;">Hapus</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="border-2 border-black bg-white px-4 py-8 text-center">
                <p class="text-sm font-['Times_New_Roman'] italic" style="color:#9ca3af;">—</p>
            </div>
            @endif
        </div>
        @endforeach

        {{-- Edit Modal --}}
        <div x-cloak x-show="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
             @keydown.escape.window="editing = null">
            @foreach($sheetNames as $name)
            @php
                $rows = $records[$name] ?? [];
                $sample = $rows[0] ?? null;
                $headers = $sample ? $sample->headers : [];
                $formulaColumns = $sample ? ($sample->formula_columns ?? []) : [];
                $editableHeaders = array_values(array_filter($headers, fn($h) => !in_array($h, $formulaColumns, true) && !in_array($h, ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'], true)));
            @endphp
            @foreach($rows as $rec)
            <div x-show="editing === {{ $rec->id }}" x-cloak
                 @click.away="editing = null"
                 class="w-full max-w-2xl border-2 border-black bg-white p-5 shadow-[8px_8px_0_0_#000] max-h-[80vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-[Helvetica] font-bold text-sm uppercase">Edit — {{ $name }}</h2>
                    <button @click="editing = null" class="text-black font-bold text-lg leading-none">&times;</button>
                </div>
                <form method="POST" action="{{ route('database.records.update', $rec) }}">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($editableHeaders as $eh)
                        <div>
                            <label class="font-[Helvetica] font-bold text-[10px] uppercase block mb-0.5">{{ $eh }}</label>
                            <input name="{{ $eh }}" value="{{ $rec->row_data[$eh] ?? '' }}"
                                   class="w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] rounded-none">
                        </div>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-2 mt-4">
                        <button type="submit" class="bg-black text-white px-6 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">Simpan</button>
                        <button type="button" @click="editing = null" class="bg-white text-black px-6 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">Batal</button>
                    </div>
                </form>
            </div>
            @endforeach
            @endforeach
        </div>

        {{-- Add Modal --}}
        <div x-cloak x-show="adding" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
             @keydown.escape.window="adding = null">
            @foreach($sheetNames as $name)
            @php
                $rows = $records[$name] ?? [];
                $sample = $rows[0] ?? null;
                $headers = $sample ? $sample->headers : [];
                $formulaColumns = $sample ? ($sample->formula_columns ?? []) : [];
                $editableHeaders = array_values(array_filter($headers, fn($h) => !in_array($h, $formulaColumns, true) && !in_array($h, ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'], true)));
            @endphp
            <div x-show="adding === '{{ $name }}'" x-cloak
                 @click.away="adding = null"
                 class="w-full max-w-2xl border-2 border-black bg-white p-5 shadow-[8px_8px_0_0_#000] max-h-[80vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-[Helvetica] font-bold text-sm uppercase">Tambah Data — {{ $name }}</h2>
                    <button @click="adding = null" class="text-black font-bold text-lg leading-none">&times;</button>
                </div>
                <form method="POST" action="{{ route('database.records.store') }}">
                    @csrf
                    <input type="hidden" name="sheet_name" value="{{ $name }}">
                    <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($editableHeaders as $eh)
                        <div>
                            <label class="font-[Helvetica] font-bold text-[10px] uppercase block mb-0.5">{{ $eh }}</label>
                            <input name="{{ $eh }}" value=""
                                   class="w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] rounded-none">
                        </div>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-2 mt-4">
                        <button type="submit" class="bg-black text-white px-6 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">Simpan</button>
                        <button type="button" @click="adding = null" class="bg-white text-black px-6 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">Batal</button>
                    </div>
                </form>
            </div>
            @endforeach
        </div>
    </div>
    @elseif($selectedBranch)
    <div class="border-2 border-black bg-white px-6 py-8 text-center">
        <p class="font-['Times_New_Roman'] text-sm">
            @if(Auth::user()->isSuperadmin())
                Belum ada data. Klik Sync Sekarang untuk memuat data.
            @else
                Database branch belum tersedia. Hubungi superadmin.
            @endif
        </p>
    </div>
    @elseif(!$selectedBranch)
    <div class="border-2 border-black bg-white px-6 py-8 text-center">
        <p class="font-['Times_New_Roman'] text-sm">
            @if(Auth::user()->isSuperadmin())
                Silakan pilih cabang terlebih dahulu.
            @else
                Database branch belum tersedia. Hubungi superadmin.
            @endif
        </p>
    </div>
    @endif
@endsection
