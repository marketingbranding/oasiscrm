@extends('layouts.crm')

@section('title', 'Lead Events - Oasis CRM')

@section('content')
    <div class="bg-[#e6915d] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Lead Events</h1>
    </div>

    @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('lead-events.index') }}" class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
        </form>
    </div>
    @endif

    <div class="flex justify-end mb-4">
        <a href="{{ route('lead-events.create') }}" class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-red-600">
            + Event Baru
        </a>
    </div>

    <div class="border-2 border-black bg-white overflow-x-auto">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Event ID</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Proyek</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Sumber Lead</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Tgl Mulai</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Tgl Selesai</th>
                    <th class="px-3 py-2 text-right font-[Helvetica] font-bold text-xs uppercase">Anggaran</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Target</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Status</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($events as $event)
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 font-bold">{{ $event->event_id ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $event->branch->name ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $event->project_name }}</td>
                    <td class="px-3 py-2">{{ $event->lead_source }}</td>
                    <td class="px-3 py-2">{{ $event->start_date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $event->end_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">{{ $event->total_budget ? 'Rp' . number_format($event->total_budget, 0, ',', '.') : '—' }}</td>
                    <td class="px-3 py-2 text-center">{{ $event->daily_target ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $event->status === 'selesai' ? 'bg-[#b3bd95]' : 'bg-[#9ab6c8]' }}">
                            {{ strtoupper($event->status) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <a href="{{ route('lead-events.edit', $event->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e6915d]">Edit</a>
                            <form method="POST" action="{{ route('lead-events.destroy', ['lead_event' => $event->id]) }}"
                                  onsubmit="return confirm('Hapus event ini?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                                <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="10" class="px-4 py-8 text-center text-sm">Belum ada event.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
