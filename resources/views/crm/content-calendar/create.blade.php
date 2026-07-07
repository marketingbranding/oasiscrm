@extends('layouts.crm')

@section('title', 'Buat Task - Oasis CRM')

@section('content')
    <x-crm.page-header color="#b3bd95" title="Buat Task Baru" />

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Task
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('content-calendar.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Task</label>
                    <input type="text" name="title" value="{{ old('title') }}" required
                        class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('title') border-[#e91d2a] @enderror">
                    @error('title')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Detail Task</label>
                    <textarea name="task_detail" rows="3" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('task_detail') border-[#e91d2a] @enderror">{{ old('task_detail') }}</textarea>
                    @error('task_detail')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <select name="branch_id" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('branch_id') border-[#e91d2a] @enderror">
                        <option value="">— Pilih Cabang —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
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
                                    <li data-value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ old('project_name') === $p->project_name ? 's-selected' : '' }}">{{ $p->project_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="project_name" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Proyek —</option>
                            @foreach($projects as $p)
                                <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" {{ old('project_name') === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Channel / Area</label>
                        <select name="platform" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('platform') border-[#e91d2a] @enderror">
                            <option value="" {{ old('platform') === null ? 'selected' : '' }}>— Pilih —</option>
                            <option value="Instagram" {{ old('platform') == 'Instagram' ? 'selected' : '' }}>Instagram</option>
                            <option value="Facebook" {{ old('platform') == 'Facebook' ? 'selected' : '' }}>Facebook</option>
                            <option value="TikTok" {{ old('platform') == 'TikTok' ? 'selected' : '' }}>TikTok</option>
                            <option value="YouTube" {{ old('platform') == 'YouTube' ? 'selected' : '' }}>YouTube</option>
                            <option value="Website" {{ old('platform') == 'Website' ? 'selected' : '' }}>Website</option>
                            <option value="Operational" {{ old('platform') == 'Operational' ? 'selected' : '' }}>Operational</option>
                            <option value="Sales" {{ old('platform') == 'Sales' ? 'selected' : '' }}>Sales</option>
                            <option value="Admin" {{ old('platform') == 'Admin' ? 'selected' : '' }}>Admin</option>
                        </select>
                        @error('platform')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Mulai</label>
                        <input type="date" name="start_date" value="{{ old('start_date') }}" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('start_date') border-[#e91d2a] @enderror">
                        @error('start_date')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Deadline</label>
                        <input type="date" name="deadline_date" value="{{ old('deadline_date') }}" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('deadline_date') border-[#e91d2a] @enderror">
                        @error('deadline_date')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status</label>
                        <select name="status" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('status') border-[#e91d2a] @enderror">
                            <option value="todo" {{ old('status', 'todo') == 'todo' ? 'selected' : '' }}>To Do</option>
                            <option value="in_progress" {{ old('status') == 'in_progress' ? 'selected' : '' }}>In Progress</option>
                            <option value="completed" {{ old('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                            <option value="lost_track" {{ old('status') == 'lost_track' ? 'selected' : '' }}>Lost Track</option>
                        </select>
                        @error('status') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Prioritas</label>
                        <select name="priority" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('priority') border-[#e91d2a] @enderror">
                            <option value="low" {{ old('priority') == 'low' ? 'selected' : '' }}>Low</option>
                            <option value="medium" {{ old('priority', 'medium') == 'medium' ? 'selected' : '' }}>Medium</option>
                            <option value="high" {{ old('priority') == 'high' ? 'selected' : '' }}>High</option>
                            <option value="urgent" {{ old('priority') == 'urgent' ? 'selected' : '' }}>Urgent</option>
                        </select>
                        @error('priority') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">PIC</label>
                        <div x-data="picTags(@json(old('pic_names', [])))" class="space-y-2">
                            <div class="flex gap-2">
                                <input type="text" x-model="draft" @keydown.enter.prevent="add()" placeholder="Ketik nama PIC..."
                                       class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('pic_names') border-[#e91d2a] @enderror">
                                <button type="button" @click="add()" class="bg-black text-white px-3 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">+</button>
                            </div>
                            <div class="flex flex-wrap gap-2" x-show="pics.length > 0">
                                <template x-for="(name, index) in pics" :key="name + index">
                                    <span class="inline-flex items-center gap-1 border-2 border-black bg-[#b3bd95] px-2 py-1 text-xs font-[Helvetica] font-bold">
                                        <span x-text="name"></span>
                                        <button type="button" @click="remove(index)" class="text-black hover:text-[#e91d2a] leading-none">×</button>
                                        <input type="hidden" name="pic_names[]" :value="name">
                                    </span>
                                </template>
                            </div>
                        </div>
                        @error('pic_names') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                        @error('pic_names.*') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Progress Notes</label>
                    <textarea name="notes" rows="4" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('notes') border-[#e91d2a] @enderror">{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan Task
                    </button>
                    <a href="{{ route('content-calendar.index', array_filter(request()->only(['month', 'year', 'branch_id', 'project_name']))) }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>

<script>
function picTags(initial) {
    return {
        draft: '',
        pics: Array.isArray(initial) ? initial.filter(Boolean) : [],
        add() {
            var name = this.draft.trim();
            if (name && !this.pics.includes(name)) {
                this.pics.push(name);
            }
            this.draft = '';
        },
        remove(index) {
            this.pics.splice(index, 1);
        }
    };
}

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
