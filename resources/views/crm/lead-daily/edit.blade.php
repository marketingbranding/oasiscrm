@extends('layouts.crm')

@section('title', 'Edit Lead Harian - Oasis CRM')

@section('content')
    <x-crm.page-header color="#e6915d" title="Edit Lead Harian" />

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Edit Harian
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('lead-daily.update', ['lead_daily' => $daily->id]) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Event</label>
                    <input type="text" value="{{ $daily->leadEvent->event_id ?? '#' . $daily->lead_event_id }} — {{ $daily->leadEvent->project_name }}" readonly
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-gray-100 rounded-none">
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label>
                    <div class="date-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="date-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('date') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="date-text">— Pilih Tanggal —</span>
                            <span class="date-arrow">▼</span>
                        </div>
                        <div class="date-calendar" style="display:none;position:absolute;top:100%;left:0;z-index:9999;border:2px solid #000;background:#fff;width:280px">
                            <div class="cal-header" style="background:#000;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:6px 10px;font-family:'Times New Roman';font-size:14px;font-weight:bold;user-select:none">
                                <button class="cal-prev" type="button" style="background:none;border:none;color:#fff;cursor:pointer;font-size:14px;padding:2px 8px;font-family:'Times New Roman';font-weight:bold;line-height:1">◀</button>
                                <span class="cal-title">Bulan Tahun</span>
                                <button class="cal-next" type="button" style="background:none;border:none;color:#fff;cursor:pointer;font-size:14px;padding:2px 8px;font-family:'Times New Roman';font-weight:bold;line-height:1">▶</button>
                            </div>
                            <div class="cal-weekdays" style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:2px solid #000;font-family:'Times New Roman';font-size:11px;font-weight:bold;text-align:center;background:#f5f5f5;color:#000">
                                <span style="padding:5px 0;border-right:1px solid #ddd">Min</span>
                                <span style="padding:5px 0;border-right:1px solid #ddd">Sen</span>
                                <span style="padding:5px 0;border-right:1px solid #ddd">Sel</span>
                                <span style="padding:5px 0;border-right:1px solid #ddd">Rab</span>
                                <span style="padding:5px 0;border-right:1px solid #ddd">Kam</span>
                                <span style="padding:5px 0;border-right:1px solid #ddd">Jum</span>
                                <span style="padding:5px 0">Sab</span>
                            </div>
                            <div class="cal-grid" style="display:grid;grid-template-columns:repeat(7,1fr);font-family:'Times New Roman';font-size:13px"></div>
                        </div>
                        <input type="date" name="date" value="{{ old('date', $daily->date->format('Y-m-d')) }}"
                               style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                    </div>
                    @error('date') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Leads Didapat</label>
                    <input type="number" name="leads_count" value="{{ old('leads_count', $daily->leads_count) }}" min="0"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('leads_count') border-[#e91d2a] @enderror">
                    @error('leads_count') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4 pt-2 border-t-2 border-black">
                    <div>
                        <span class="font-[Helvetica] font-bold text-xs uppercase">Hari Ke</span>
                        <p class="text-sm font-['Times_New_Roman'] mt-1">{{ $daily->day_number ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="font-[Helvetica] font-bold text-xs uppercase">Leads Kumulatif</span>
                        <p class="text-sm font-['Times_New_Roman'] mt-1">{{ $daily->cumulative_leads }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan
                    </button>
                    <a href="{{ route('lead-daily.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>

            <div class="border-t-2 border-black mt-6 pt-4">
                <form method="POST" action="{{ route('lead-daily.destroy', ['lead_daily' => $daily->id]) }}"
                      onsubmit="return confirm('Hapus data harian ini?')">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                    <input type="hidden" name="lead_event_id" value="{{ request('lead_event_id') }}">
                    <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                    <button type="submit" class="bg-[#e91d2a] text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-red-600">
                        Hapus Data Harian
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
