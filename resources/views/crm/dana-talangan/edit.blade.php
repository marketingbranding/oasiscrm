@extends('layouts.crm')

@section('title', 'Edit Dana Talangan - Oasis CRM')

@section('content')
    <x-crm.page-header color="#f1c40f" title="Edit Dana Talangan" />

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Dana Talangan
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('dana-talangan.update', ['dana_talangan' => $record->id]) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label>
                        <div class="date-wrapper" data-accent="#f1c40f" style="position:relative">
                            <div class="date-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('tanggal') border-[#e91d2a] @enderror" tabindex="0">
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
                            <input type="date" name="tanggal" value="{{ old('tanggal', $record->tanggal->format('Y-m-d')) }}" required
                                   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                        </div>
                        @error('tanggal') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Konsumen</label>
                        <input type="text" name="nama_konsumen" value="{{ old('nama_konsumen', $record->nama_konsumen) }}" required
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('nama_konsumen') border-[#e91d2a] @enderror">
                        @error('nama_konsumen') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
                @php $cabangError = $errors->has('branch_id') ? 'border-[#e91d2a]' : 'border-black'; @endphp
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <div class="select-wrapper" data-accent="#f1c40f" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between {{ $cabangError }}" tabindex="0">
                            <span class="select-text">— Pilih Cabang —</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($branches as $b)
                                    <li data-value="{{ $b->id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ old('branch_id', $record->branch_id) == $b->id ? 's-selected' : '' }}">{{ $b->name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="branch_id" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Cabang —</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ old('branch_id', $record->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('branch_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
                @endif

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                    <div class="select-wrapper" data-accent="#f1c40f" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black" tabindex="0">
                            <span class="select-text">— Pilih Proyek —</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($projects as $p)
                                    <li data-value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ old('project_name', $record->project_name) === $p->project_name ? 's-selected' : '' }}">{{ $p->project_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="project_name" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Proyek —</option>
                            @foreach($projects as $p)
                                <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" {{ old('project_name', $record->project_name) === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Kav</label>
                        <div class="select-wrapper" data-accent="#f1c40f" style="position:relative">
                            <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('kav') border-[#e91d2a] @enderror" tabindex="0">
                                <span class="select-text">— Pilih Kav —</span>
                                <span class="select-arrow">▼</span>
                            </div>
                            <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                                <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                                <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                </ul>
                            </div>
                            <select name="kav" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                                <option value="">— Pilih Kav —</option>
                            </select>
                        </div>
                        @error('kav') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Pinjam Nama</label>
                        <select name="pinjam_nama" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                            <option value="0" {{ old('pinjam_nama', $record->pinjam_nama) ? '' : 'selected' }}>TIDAK</option>
                            <option value="1" {{ old('pinjam_nama', $record->pinjam_nama) ? 'selected' : '' }}>YA</option>
                        </select>
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Umur</label>
                        <input type="number" name="umur" value="{{ old('umur', $record->umur) }}" min="0" max="150"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('umur') border-[#e91d2a] @enderror">
                        @error('umur') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Pekerjaan</label>
                        <input type="text" name="pekerjaan" value="{{ old('pekerjaan', $record->pekerjaan) }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('pekerjaan') border-[#e91d2a] @enderror">
                        @error('pekerjaan') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Perkawinan</label>
                        <input type="text" name="status_perkawinan" value="{{ old('status_perkawinan', $record->status_perkawinan) }}" placeholder="Cth: KAWIN (ANAK 2)"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('status_perkawinan') border-[#e91d2a] @enderror">
                        @error('status_perkawinan') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Marketing</label>
                    <input type="text" name="nama_marketing" value="{{ old('nama_marketing', $record->nama_marketing) }}"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('nama_marketing') border-[#e91d2a] @enderror">
                    @error('nama_marketing') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">TGL Komitmen</label>
                    <div class="date-wrapper" data-accent="#f1c40f" style="position:relative">
                        <div class="date-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('tgl_komitmen') border-[#e91d2a] @enderror" tabindex="0">
                            <span class="date-text">— Pilih TGL Komitmen —</span>
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
                        <input type="date" name="tgl_komitmen" value="{{ old('tgl_komitmen', $record->tgl_komitmen ? \Carbon\Carbon::parse($record->tgl_komitmen)->format('Y-m-d') : '') }}"
                               style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                    </div>
                    @error('tgl_komitmen') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Progress Penagihan</label>
                    <textarea name="penyelesaian" rows="3" placeholder="Contoh: Follow up WA 08 Jul, konsumen janji bayar 12 Jul, menunggu bukti transfer."
                              class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('penyelesaian') border-[#e91d2a] @enderror">{{ old('penyelesaian', $record->penyelesaian) }}</textarea>
                    @error('penyelesaian') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="border-t-2 border-black pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Konfirmasi Keuangan</label>
                        <select name="konfirmasi_keuangan" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                            <option value="0" {{ old('konfirmasi_keuangan', $record->konfirmasi_keuangan) ? '' : 'selected' }}>Belum Dikonfirmasi</option>
                            <option value="1" {{ old('konfirmasi_keuangan', $record->konfirmasi_keuangan) ? 'selected' : '' }}>Sudah Dikonfirmasi</option>
                        </select>
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Cicilan</label>
                        <select name="status" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                            <option value="sanggup" {{ old('status', $record->status) === 'sanggup' ? 'selected' : '' }}>Sanggup</option>
                            <option value="tidak_sanggup" {{ old('status', $record->status) === 'tidak_sanggup' ? 'selected' : '' }}>Tidak Sanggup</option>
                            <option value="lunas" {{ old('status', $record->status) === 'lunas' ? 'selected' : '' }}>Lunas</option>
                        </select>
                        @error('status') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <div class="flex items-center gap-3">
                        <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                            Update
                        </button>
                        <a href="{{ route('dana-talangan.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                            Batal
                        </a>
                    </div>
                </div>
            </form>

            <form method="POST" action="{{ route('dana-talangan.destroy', ['dana_talangan' => $record->id]) }}"
                  onsubmit="return confirm('Hapus data ini?')" class="pt-4">
                @csrf
                @method('DELETE')
                <button type="submit" class="bg-white text-[#e91d2a] px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-[#e91d2a] rounded-none hover:bg-red-50">
                    Hapus
                </button>
            </form>
        </div>
    </div>

<script>
var projectData = [
    @foreach($projects as $p)
    { id: @json($p->id), name: @json($p->project_name), branch: @json($p->branch_id) },
    @endforeach
];

var kavlingData = [
    @foreach($kavlings as $k)
    { code: @json($k->kavling_code), name: @json($k->name), project: @json($k->project_id) },
    @endforeach
];

document.addEventListener('DOMContentLoaded', function() {
    var branchSelect = document.querySelector('[name="branch_id"]');
    if (branchSelect) {
        branchSelect.addEventListener('change', function() {
            var branchId = this.value;
            var proyekSelect = document.querySelector('[name="project_name"]');
            if (!proyekSelect) return;

            var wrapper = proyekSelect.closest('.select-wrapper');
            var proyekList = wrapper.querySelector('.select-options');
            var proyekText = wrapper.querySelector('.select-text');
            var currentVal = proyekSelect.value;

            while (proyekSelect.options.length > 1) proyekSelect.remove(1);
            proyekList.innerHTML = '';

            var ph = document.createElement('li');
            ph.setAttribute('data-value', '');
            ph.textContent = '\u2014 Pilih Proyek \u2014';
            ph.style.cssText = 'padding:6px 12px;font-size:13px;font-family:\'Times New Roman\';cursor:pointer';
            ph.className = 'select-li';
            proyekList.appendChild(ph);

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
                    proyekList.appendChild(li);
                }
            }

            proyekText.textContent = hasMatch ? currentVal : '\u2014 Pilih Proyek \u2014';
            if (!hasMatch) proyekSelect.value = '';
        });

        if (branchSelect.value) {
            var evt = new Event('change', { bubbles: true });
            branchSelect.dispatchEvent(evt);
        }
    }

    var proyekSelect = document.querySelector('[name="project_name"]');
    if (proyekSelect) {
        proyekSelect.addEventListener('change', function() {
            updateKavDropdown(this.value);
        });
        if (proyekSelect.value) {
            updateKavDropdown(proyekSelect.value, @json(old('kav', $record->kav)));
        }
    }

    function updateKavDropdown(projectName, preSelectValue) {
        var selected = null;
        for (var i = 0; i < projectData.length; i++) {
            if (projectData[i].name === projectName) { selected = projectData[i]; break; }
        }
        var projectId = selected ? selected.id : null;
        var kavSelect = document.querySelector('[name="kav"]');
        if (!kavSelect) return;
        var wrapper = kavSelect.closest('.select-wrapper');
        var kavList = wrapper ? wrapper.querySelector('.select-options') : null;
        var kavText = wrapper ? wrapper.querySelector('.select-text') : null;
        var currentKav = typeof preSelectValue !== 'undefined' ? preSelectValue : kavSelect.value;

        while (kavSelect.options.length > 1) kavSelect.remove(1);
        if (kavList) kavList.innerHTML = '';

        if (projectId) {
            for (var i = 0; i < kavlingData.length; i++) {
                if (kavlingData[i].project == projectId) {
                    var k = kavlingData[i];
                    var opt = document.createElement('option');
                    opt.value = k.code;
                    opt.textContent = k.code;
                    if (currentKav && k.code === currentKav) opt.selected = true;
                    kavSelect.add(opt);

                    if (kavList) {
                        var li = document.createElement('li');
                        li.setAttribute('data-value', k.code);
                        li.textContent = k.code;
                        li.style.cssText = 'padding:6px 12px;font-size:13px;font-family:\'Times New Roman\';cursor:pointer';
                        li.className = 'select-li';
                        if (currentKav && k.code === currentKav) li.classList.add('s-selected');
                        kavList.appendChild(li);
                    }
                }
            }
        }

        if (kavText) {
            if (kavSelect.selectedIndex > 0) {
                kavText.textContent = kavSelect.options[kavSelect.selectedIndex].text;
            } else {
                kavText.textContent = '\u2014 Pilih Kav \u2014';
            }
        }
    }
});
</script>
@endsection
