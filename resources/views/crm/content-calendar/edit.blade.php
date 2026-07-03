@extends('layouts.crm')

@section('title', 'Edit Konten - Oasis CRM')

@section('content')
    <x-crm.page-header color="#b3bd95" title="Edit Konten" />

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Konten
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('content-calendar.update', ['content_calendar' => $content->id]) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="month" value="{{ request('month') }}">
                <input type="hidden" name="year" value="{{ request('year') }}">
                @if(Auth::user()->canViewAllBranches())
                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                @endif
                <input type="hidden" name="project_name" value="{{ request('project_name') }}">

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Judul Konten</label>
                    <input type="text" name="title" value="{{ old('title', $content->title) }}" required
                        class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('title') border-[#e91d2a] @enderror">
                    @error('title')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <select name="branch_id" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('branch_id') border-[#e91d2a] @enderror">
                        <option value="">— Pilih Cabang —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id', $content->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>
                @endif

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                    <div class="select-wrapper" data-accent="#b3bd95" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black" tabindex="0">
                            <span class="select-text">— Pilih Proyek —</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($projects as $p)
                                    <li data-value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ old('project_name', $content->project_name) === $p->project_name ? 's-selected' : '' }}">{{ $p->project_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="project_name" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Proyek —</option>
                            @foreach($projects as $p)
                                <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" {{ old('project_name', $content->project_name) === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Platform</label>
                        <select name="platform" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('platform') border-[#e91d2a] @enderror">
                            <option value="Instagram" {{ old('platform', $content->platform) == 'Instagram' ? 'selected' : '' }}>Instagram</option>
                            <option value="Facebook" {{ old('platform', $content->platform) == 'Facebook' ? 'selected' : '' }}>Facebook</option>
                            <option value="TikTok" {{ old('platform', $content->platform) == 'TikTok' ? 'selected' : '' }}>TikTok</option>
                            <option value="YouTube" {{ old('platform', $content->platform) == 'YouTube' ? 'selected' : '' }}>YouTube</option>
                            <option value="Website" {{ old('platform', $content->platform) == 'Website' ? 'selected' : '' }}>Website</option>
                        </select>
                        @error('platform')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal Jadwal</label>
                        <div class="date-wrapper" data-accent="#b3bd95" style="position:relative">
                            <div class="date-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('scheduled_date') border-[#e91d2a] @enderror" tabindex="0">
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
                            <input type="date" name="scheduled_date" value="{{ old('scheduled_date', $content->scheduled_date->format('Y-m-d')) }}" required
                                   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                        </div>
                        @error('scheduled_date')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status</label>
                        <select name="status" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('status') border-[#e91d2a] @enderror">
                            <option value="draft" {{ old('status', $content->status) == 'draft' ? 'selected' : '' }}>Draft</option>
                            <option value="review" {{ old('status', $content->status) == 'review' ? 'selected' : '' }}>Review</option>
                            <option value="approved" {{ old('status', $content->status) == 'approved' ? 'selected' : '' }}>Approved</option>
                            <option value="posted" {{ old('status', $content->status) == 'posted' ? 'selected' : '' }}>Posted</option>
                        </select>
                        @error('status')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Catatan</label>
                    <textarea name="notes" rows="4" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('notes') border-[#e91d2a] @enderror">{{ old('notes', $content->notes) }}</textarea>
                    @error('notes')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-between pt-2">
                    <div class="flex items-center gap-3">
                        <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                            Update Konten
                        </button>
                        <a href="{{ route('content-calendar.index', array_filter(request()->only(['month', 'year', 'branch_id', 'project_name']))) }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                            Batal
                        </a>
                    </div>
                    <form method="POST" action="{{ route('content-calendar.destroy', ['content_calendar' => $content->id]) }}" onsubmit="return confirm('Yakin ingin menghapus konten ini?')">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="month" value="{{ request('month') }}">
                        <input type="hidden" name="year" value="{{ request('year') }}">
                        @if(Auth::user()->canViewAllBranches())
                        <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                        @endif
                        <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                        <button type="submit" class="bg-white text-[#e91d2a] px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-[#e91d2a] rounded-none hover:bg-red-50">
                            Hapus
                        </button>
                    </form>
                </div>
            </form>
        </div>
    </div>

<script>
var projectData = [
    @foreach($projects as $p)
    { name: @json($p->project_name), branch: @json($p->branch_id) },
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
});
</script>
@endsection
