@extends('layouts.crm')

@section('title', 'Daily Leads - Oasis CRM')

@section('content')
    <div class="bg-[#e6915d] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Daily Leads</h1>
    </div>

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('lead-daily.index') }}" class="flex items-center gap-3 flex-wrap">
            @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
            <label class="font-[Helvetica] font-bold text-xs uppercase">Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
            @endif

            <label class="font-[Helvetica] font-bold text-xs uppercase">Event:</label>
            <select name="lead_event_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Event —</option>
                @foreach($events as $e)
                    <option value="{{ $e->id }}" {{ (string)$selectedEventId === (string)$e->id ? 'selected' : '' }}>{{ $e->event_id ?? $e->project_name }} — {{ $e->branch->name ?? '' }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="flex justify-end mb-4">
        <a href="{{ route('lead-daily.create') }}" class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-red-600">
            + Input Harian
        </a>
    </div>

    <div class="border-2 border-black bg-white overflow-x-auto">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Tanggal</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Event</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Proyek</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Hari Ke</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Target</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Leads</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Kumulatif</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Achieve %</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($dailyLogs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2">{{ $log->date->format('d M Y') }}</td>
                    <td class="px-3 py-2 font-bold">{{ $log->leadEvent->event_id ?? '#' . $log->lead_event_id }}</td>
                    <td class="px-3 py-2">{{ $log->branch->name ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $log->leadEvent->project_name }}</td>
                    <td class="px-3 py-2 text-center">{{ $log->day_number ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">{{ $log->leadEvent->daily_target ?? '—' }}</td>
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
@endsection
