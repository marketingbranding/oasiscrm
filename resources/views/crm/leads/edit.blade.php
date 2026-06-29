@extends('layouts.crm')

@section('title', 'Edit Lead - Oasis CRM')

@section('content')
<x-crm.page-header color="#e6915d" title="Edit Lead" />

<div class="border-2 border-black bg-white">
    <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
        <span class="text-[#e6915d]">ID Lead: {{ $lead->id_lead }}</span>
    </div>
    <div class="p-4 sm:p-6">
        <form method="POST" action="{{ route('leads.update', $lead->id) }}" class="space-y-4">
            @csrf
            @method('PUT')

            @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
            @php $cabangError = $errors->has('branch_id') ? 'border-[#e91d2a]' : 'border-black'; @endphp
            <div>
                <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                <div class="select-wrapper" data-accent="#e6915d" style="position:relative">
                    <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between {{ $cabangError }}" tabindex="0">
                        <span class="select-text">{{ $lead->branch->name ?? '— Pilih Cabang —' }}</span>
                        <span class="select-arrow">▼</span>
                    </div>
                    <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                        <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                        <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                            @foreach($branches as $b)
                                <li data-value="{{ $b->id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ $lead->branch_id == $b->id ? 's-selected' : '' }}">{{ $b->name }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <select name="branch_id" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                        <option value="">— Pilih Cabang —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ $lead->branch_id == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                @error('branch_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
            </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal Lead</label>
                    <div class="date-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="date-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('tanggal_lead') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="date-text">{{ $lead->tanggal_lead->format('d M Y') }}</span>
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
                        <input type="date" name="tanggal_lead" value="{{ old('tanggal_lead', $lead->tanggal_lead->format('Y-m-d')) }}" required
                               style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                    </div>
                    @error('tanggal_lead') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Konsumen</label>
                    <input type="text" name="nama_konsumen" value="{{ old('nama_konsumen', $lead->nama_konsumen) }}" required
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('nama_konsumen') border-[#e91d2a] @enderror">
                    @error('nama_konsumen') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <label class="font-[Helvetica] font-bold text-xs uppercase">Sumber</label>
                        <button type="button" class="select-add-btn bg-[#e6915d] text-white border-2 border-black px-1.5 py-0 text-xs font-bold leading-none rounded-none hover:bg-[#d4854f] cursor-pointer">+</button>
                    </div>
                    <div class="select-wrapper" data-accent="#e6915d" data-inline-manage="sumber" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('sumber') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="select-text">{{ $lead->sumber }}</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($sources as $src)
                                    <li data-value="{{ $src->name }}" data-source-id="{{ $src->id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ $lead->sumber === $src->name ? 's-selected' : '' }}">{{ $src->name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="sumber" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Sumber —</option>
                            @foreach($sources as $src)
                                <option value="{{ $src->name }}" data-source-id="{{ $src->id }}" {{ $lead->sumber === $src->name ? 'selected' : '' }}>{{ $src->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('sumber') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Platform</label>
                    <div class="select-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('platform') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="select-text">{{ $lead->platform }}</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($platforms as $pl)
                                    <li data-value="{{ $pl }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ $lead->platform === $pl ? 's-selected' : '' }}">{{ $pl }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="platform" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Platform —</option>
                            @foreach($platforms as $pl)
                                <option value="{{ $pl }}" {{ $lead->platform === $pl ? 'selected' : '' }}>{{ $pl }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('platform') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Campaign</label>
                    <div class="select-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('campaign') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="select-text">{{ $lead->campaign }}</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($campaigns as $c)
                                    <li data-value="{{ $c }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ $lead->campaign === $c ? 's-selected' : '' }}">{{ $c }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="campaign" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Campaign —</option>
                            @foreach($campaigns as $c)
                                <option value="{{ $c }}" {{ $lead->campaign === $c ? 'selected' : '' }}>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('campaign') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">ID Promo</label>
                    <div class="select-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('id_promo') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="select-text">{{ $lead->id_promo ?? '— Pilih Promo —' }}</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @forelse($promos as $p)
                                    <li data-value="{{ $p }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ $lead->id_promo === $p ? 's-selected' : '' }}">{{ $p }}</li>
                                @empty
                                    <li data-value="" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:default;color:#999">Tidak ada data promo</li>
                                @endforelse
                            </ul>
                        </div>
                        <select name="id_promo" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Promo —</option>
                            @foreach($promos as $p)
                                <option value="{{ $p }}" {{ $lead->id_promo === $p ? 'selected' : '' }}>{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('id_promo') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">No HP</label>
                    <input type="text" name="no_hp" value="{{ old('no_hp', $lead->no_hp) }}" placeholder="08xxxxxxxxxx"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('no_hp') border-[#e91d2a] @enderror">
                    @error('no_hp') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                    <div class="select-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('proyek') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="select-text">{{ $lead->proyek }}</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($projects as $p)
                                    <li data-value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ $lead->proyek === $p->project_name ? 's-selected' : '' }}">{{ $p->project_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="proyek" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Proyek —</option>
                            @foreach($projects as $p)
                                <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" {{ $lead->proyek === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('proyek') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Sales PIC</label>
                    <div class="select-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('sales_pic') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="select-text">{{ $lead->sales_pic }}</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @forelse($salesPics as $sp)
                                    <li data-value="{{ $sp }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ $lead->sales_pic === $sp ? 's-selected' : '' }}">{{ $sp }}</li>
                                @empty
                                    <li data-value="" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:default;color:#999">Tidak ada data sales</li>
                                @endforelse
                            </ul>
                        </div>
                        <select name="sales_pic" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Sales —</option>
                            @foreach($salesPics as $sp)
                                <option value="{{ $sp }}" {{ $lead->sales_pic === $sp ? 'selected' : '' }}>{{ $sp }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('sales_pic') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Lead</label>
                    <div class="select-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('status_lead') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="select-text">{{ $lead->status_lead }}</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($statuses as $st)
                                    <li data-value="{{ $st }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ $lead->status_lead === $st ? 's-selected' : '' }}">{{ $st }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="status_lead" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Status —</option>
                            @foreach($statuses as $st)
                                <option value="{{ $st }}" {{ $lead->status_lead === $st ? 'selected' : '' }}>{{ $st }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('status_lead') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Keterangan</label>
                    <textarea name="keterangan" rows="3" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('keterangan') border-[#e91d2a] @enderror">{{ old('keterangan', $lead->keterangan) }}</textarea>
                    @error('keterangan') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                    Simpan
                </button>
                <a href="{{ route('leads.index', array_filter(request()->only(['branch_id', 'proyek']))) }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    Batal
                </a>
            </div>
        </form>
    </div>
</div>

<script>
var projectData = [
    @foreach($allProjects as $p)
    { id: @json($p->id), name: @json($p->project_name), branch: @json($p->branch_id) },
    @endforeach
];

document.addEventListener('DOMContentLoaded', function() {
    var branchSelect = document.querySelector('[name="branch_id"]');
    if (branchSelect) {
        branchSelect.addEventListener('change', function() {
            var branchId = this.value;
            var proyekSelect = document.querySelector('[name="proyek"]');
            if (!proyekSelect) return;

            var wrapper = proyekSelect.closest('.select-wrapper');
            var list = wrapper.querySelector('.select-options');
            var text = wrapper.querySelector('.select-text');
            var currentVal = proyekSelect.value;

            while (proyekSelect.options.length > 1) proyekSelect.remove(1);
            list.innerHTML = '';

            var ph = document.createElement('li');
            ph.setAttribute('data-value', '');
            ph.textContent = '\u2014 Pilih Proyek \u2014';
            ph.style.cssText = 'padding:6px 12px;font-size:13px;font-family:\'Times New Roman\';cursor:pointer';
            ph.className = 'select-li';
            list.appendChild(ph);

            var hasMatch = false;
            for (var i = 0; i < projectData.length; i++) {
                if (!branchId || !projectData[i].branch || projectData[i].branch == branchId) {
                    var opt = document.createElement('option');
                    opt.value = projectData[i].name;
                    opt.textContent = projectData[i].name;
                    if (projectData[i].name === currentVal) { opt.selected = true; hasMatch = true; }
                    proyekSelect.add(opt);

                    var li = document.createElement('li');
                    li.setAttribute('data-value', projectData[i].name);
                    li.textContent = projectData[i].name;
                    li.style.cssText = 'padding:6px 12px;font-size:13px;font-family:\'Times New Roman\';cursor:pointer';
                    li.className = 'select-li';
                    if (projectData[i].name === currentVal) li.classList.add('s-selected');
                    list.appendChild(li);
                }
            }

            text.textContent = hasMatch ? currentVal : '\u2014 Pilih Proyek \u2014';
            if (!hasMatch) proyekSelect.value = '';
        });

        if (branchSelect.value) {
            var evt = new Event('change', { bubbles: true });
            branchSelect.dispatchEvent(evt);
        }
    }

    var sumberWrapper = document.querySelector('.select-wrapper[data-inline-manage="sumber"]');
    if (sumberWrapper) {
        var origRefresh = sumberWrapper.__refreshOptions;
        sumberWrapper.__refreshOptions = function() {
            if (origRefresh) origRefresh();
            var list = sumberWrapper.querySelector('.select-options');
            Array.from(list.children).forEach(function(li) {
                if (!li.dataset.value || li.dataset.value === '') return;
                li.dataset.text = li.textContent;
                li.textContent = '';
                var span = document.createElement('span');
                span.textContent = li.dataset.text;
                span.className = 'select-li-text';
                li.appendChild(span);
                var delBtn = document.createElement('button');
                delBtn.textContent = '\u00D7';
                delBtn.className = 'select-del-btn';
                delBtn.style.cssText = 'margin-left:auto;background:none;border:none;cursor:pointer;font-size:15px;line-height:1;padding:0 4px;color:#e91d2a;font-weight:bold';
                li.appendChild(delBtn);
                li.style.display = 'flex';
                li.style.alignItems = 'center';
                li.style.justifyContent = 'space-between';
            });
        };

        sumberWrapper.addEventListener('click', function(e) {
            var delBtn = e.target.closest('.select-del-btn');
            if (delBtn && sumberWrapper.contains(delBtn)) {
                e.stopPropagation();
                e.preventDefault();
                var li = delBtn.closest('li');
                if (!li || !li.dataset.value) return;
                var select = sumberWrapper.querySelector('select');
                var opt = select.querySelector('option[value="' + li.dataset.value + '"]');
                if (!opt) return;
                var sourceId = opt.getAttribute('data-source-id');
                var name = li.dataset.text || opt.textContent;
                if (!confirm('Hapus sumber "' + name + '"?')) return;
                fetch('/lead-sources/' + sourceId, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' }
                })
                .then(function(r) {
                    if (!r.ok) return r.json().then(function(err) { throw new Error(err.message || 'Gagal menghapus sumber'); });
                    return r.json();
                })
                .then(function() {
                    opt.remove();
                    sumberWrapper.__refreshOptions();
                })
                .catch(function(err) { alert(err.message); });
            }
        }, true);

        var list = sumberWrapper.querySelector('.select-options');
        list.addEventListener('click', function(e) {
            var li = e.target.closest('li');
            if (li && li.dataset.text) {
                var textEl = sumberWrapper.querySelector('.select-text');
                if (textEl) textEl.textContent = li.dataset.text;
            }
        });

        sumberWrapper.__refreshOptions();
    }

    var sumberModal = (function() {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.4);align-items:center;justify-content:center';
        overlay.innerHTML =
            '<div style="background:#fff;border:2px solid #000;padding:20px;min-width:300px;margin:auto">' +
            '<p style="font-family:\'Times New Roman\';font-size:14px;font-weight:bold;margin:0 0 12px">Nama Sumber Baru</p>' +
            '<input type="text" id="sumber-input" style="width:100%;border:2px solid #000;padding:8px;font-size:14px;font-family:\'Times New Roman\';box-sizing:border-box">' +
            '<div style="display:flex;gap:8px;margin-top:12px">' +
            '<button id="sumber-save" style="background:#000;color:#fff;border:2px solid #000;padding:6px 16px;font-size:13px;font-family:\'Times New Roman\';font-weight:bold;cursor:pointer">Simpan</button>' +
            '<button id="sumber-cancel" style="background:#fff;color:#000;border:2px solid #000;padding:6px 16px;font-size:13px;font-family:\'Times New Roman\';cursor:pointer">Batal</button>' +
            '</div></div>';
        overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.style.display = 'none'; });
        document.body.appendChild(overlay);
        return overlay;
    })();

    document.addEventListener('click', function(e) {
        if (!e.target.classList.contains('select-add-btn')) return;
        e.preventDefault();
        var wrapper = document.querySelector('.select-wrapper[data-inline-manage="sumber"]');
        var input = sumberModal.querySelector('#sumber-input');
        input.value = '';
        sumberModal.style.display = 'flex';
        input.focus();

        function cleanup() {
            sumberModal.style.display = 'none';
            sumberModal.querySelector('#sumber-save').removeEventListener('click', onSave);
            sumberModal.querySelector('#sumber-cancel').removeEventListener('click', onCancel);
            input.removeEventListener('keydown', onKey);
        }

        function onSave() {
            var name = input.value.trim();
            if (!name) return;
            cleanup();
            fetch('/lead-sources', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                body: JSON.stringify({ name: name })
            })
            .then(function(r) {
                if (!r.ok) return r.json().then(function(err) { var msg = 'Gagal menambah sumber'; if (err.errors && err.errors.name) msg = err.errors.name[0]; throw new Error(msg); });
                return r.json();
            })
            .then(function(data) {
                var select = wrapper.querySelector('select');
                var opt = document.createElement('option');
                opt.value = data.name; opt.textContent = data.name; opt.setAttribute('data-source-id', data.id);
                select.add(opt);
                if (wrapper.__refreshOptions) wrapper.__refreshOptions();
            })
            .catch(function(err) { alert(err.message); });
        }

        function onCancel() { cleanup(); }

        function onKey(e) { if (e.key === 'Enter') onSave(); if (e.key === 'Escape') onCancel(); }

        sumberModal.querySelector('#sumber-save').addEventListener('click', onSave);
        sumberModal.querySelector('#sumber-cancel').addEventListener('click', onCancel);
        input.addEventListener('keydown', onKey);
    });
});
</script>
@endsection
