@extends('layouts.crm')

@section('title', 'Lead Harian - Oasis CRM')

@section('content')
    <x-crm.page-header color="#e6915d" title="Lead Harian" />

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('lead-daily.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
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

            <label class="font-[Helvetica] font-bold text-xs uppercase">Event:</label>
            <select name="lead_event_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Event —</option>
                @foreach($events as $e)
                    <option value="{{ $e->id }}" {{ (string)$selectedEventId === (string)$e->id ? 'selected' : '' }}>{{ $e->event_id ?? $e->project_name }} — {{ $e->branch->name ?? '' }}</option>
                @endforeach
            </select>
                <label class="font-[Helvetica] font-bold text-xs uppercase">Cari:</label>
                <input name="search" value="{{ request('search') }}"
                       placeholder="Proyek, Lokasi..."
                       class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none"
                       onkeydown="if(event.key==='Enter') this.form.submit()">
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <x-crm.export-import export-route="lead-daily.export" import-route="lead-daily.import" :params="request()->only(['branch_id', 'lead_event_id', 'project_name'])" />
                <a href="{{ route('lead-daily.create') }}" class="bg-[#c0392b] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#a93226]">
                    + Input Harian
                </a>
            </div>
        </form>
    </div>

    <div class="table-scroll border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                    <x-crm.sortable-th field="date" route="lead-daily.index" label="Tanggal" :currentSort="$sortField" :currentDir="$sortDir" />
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Event</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Proyek</th>
                    <x-crm.sortable-th field="day_number" route="lead-daily.index" label="Hari Ke" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <x-crm.sortable-th field="leads_count" route="lead-daily.index" label="Leads" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <x-crm.sortable-th field="cumulative_leads" route="lead-daily.index" label="Kumulatif" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <x-crm.sortable-th field="achievement_pct" route="lead-daily.index" label="Achieve %" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($dailyLogs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="w-10 px-3 py-2 text-center"><input type="checkbox" class="row-checkbox cursor-pointer" value="{{ $log->id }}"></td>
                    <td class="px-3 py-2">{{ $log->date->format('d M Y') }}</td>
                    <td class="px-3 py-2 font-bold">{{ $log->leadEvent->event_id ?? '#' . $log->lead_event_id }}</td>
                    <td class="px-3 py-2">{{ $log->branch->name ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $log->leadEvent->project_name }}</td>
                    <td class="px-3 py-2 text-center">{{ $log->day_number ?? '—' }}</td>
                    <td class="px-3 py-2 text-center font-bold">{{ $log->leads_count }}</td>
                    <td class="px-3 py-2 text-center">{{ $log->cumulative_leads }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($log->achievement_pct !== null)
                            {{ number_format($log->achievement_pct, 0) }}%
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <a href="{{ route('lead-daily.edit', $log->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e6915d]">Edit</a>
                            <form method="POST" action="{{ route('lead-daily.destroy', ['lead_daily' => $log->id]) }}"
                                  onsubmit="return confirm('Hapus data harian ini?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                                <input type="hidden" name="lead_event_id" value="{{ request('lead_event_id') }}">
                                <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                                <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="10" class="px-4 py-8 text-center text-sm">Belum ada data harian.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-crm.pagination :collection="$dailyLogs" :per-page="$perPage" />

    <x-crm.bulk-bar
        destroy-route="{{ route('lead-daily.bulk-destroy') }}"
        :params="request()->only(['branch_id', 'lead_event_id', 'project_name'])" />

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
