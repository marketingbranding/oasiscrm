@php
    $editing = isset($item) && $item;
    $type = old('item_type', $item?->item_type ?? $defaultType ?? 'task');
    $branchId = old('branch_id', $item?->branch_id ?? Auth::user()->branch_id);
    $selectedUsers = old('assigned_user_ids', $item?->assignees?->pluck('id')->all() ?? []);
    $externalPics = old('pic_names', $item?->pic_names ?? []);
@endphp
<form method="POST" action="{{ $editing ? route('content-calendar.update', $item) : route('content-calendar.store') }}"
      @if($editing) data-conflict-form @endif
      x-data="plannerForm({
        type: @js($type),
        branchId: @js((string) $branchId),
        projectName: @js(old('project_name', $item?->project_name)),
        status: @js(old('status', $item?->status ?? ($type === 'agenda' ? 'planned' : ($type === 'content' ? 'idea' : 'todo')))),
        agendaType: @js(old('agenda_type', $item?->agenda_type ?? 'visit')),
        tujuanKonten: @js(old('tujuan_konten', $item?->tujuan_konten ?? 'Edukasi')),
        platform: @js(old('platform', $item?->platform ?? 'Sosial Media')),
        contentFormat: @js(old('content_format', $item?->content_format ?? 'Video')),
        projects: @js($projects->map(fn($project) => ['name' => $project->project_name, 'branch_id' => (string) $project->branch_id])->values()),
        users: @js($users->map(fn($user) => ['id' => $user->id, 'name' => $user->name, 'branch_ids' => collect([$user->branch_id])->merge($user->branches->pluck('id'))->filter()->map(fn($id) => (string) $id)->unique()->values()])->values()),
        externalPics: @js($externalPics),
    })" class="space-y-5">
    @csrf
    @if($editing) @method('PUT') @endif
    @if($editing)<input type="hidden" name="expected_updated_at" value="{{ old('expected_updated_at', $item->updated_at?->copy()->utc()->format('Y-m-d H:i:s')) }}">@endif
    <input type="hidden" name="return_view" value="{{ request('view', 'today') }}">

    <div role="group" aria-label="Jenis Aktivitas">
        <div class="font-[Helvetica] font-bold text-xs uppercase block mb-2">Jenis Aktivitas</div>
        <div class="grid grid-cols-3 gap-2">
            <template x-for="option in typeOptions" :key="option.value">
                <button type="button" @click="changeType(option.value)"
                        :aria-pressed="(type === option.value).toString()"
                        class="border-2 border-black px-3 py-3 text-sm font-[Helvetica] font-bold"
                        :class="type === option.value ? option.active : 'bg-white hover:bg-gray-100'">
                    <span x-text="option.label"></span>
                </button>
            </template>
        </div>
        <input type="hidden" name="item_type" :value="type">
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-crm.field class="sm:col-span-2" label="Judul" for="planner-title" required :error="$errors->first('title')">
            <input id="planner-title" name="title" value="{{ old('title', $item?->title) }}" required class="crm-control font-['Times_New_Roman']">
        </x-crm.field>
        <x-crm.field class="sm:col-span-2" x-show="type !== 'content'" label="Detail" for="planner-task-detail" :error="$errors->first('task_detail')">
            <textarea id="planner-task-detail" name="task_detail" rows="3" :disabled="type === 'content'" class="crm-control font-['Times_New_Roman']">{{ old('task_detail', $item?->task_detail) }}</textarea>
        </x-crm.field>

        @if($branches->count() > 1)
        <x-crm.field label="Cabang" for="planner-branch" required :error="$errors->first('branch_id')">
            <select id="planner-branch" name="branch_id" x-model="branchId" @change="projectName = ''" required class="crm-control bg-white">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @if(str_contains(mb_strtolower($branch->name), 'pusat')) style="color:#b8860b;font-weight:700;background:#fff3b0" @endif>{{ $branch->name }}</option>@endforeach
            </select>
        </x-crm.field>
        @endif
        <x-crm.field x-show="type !== 'content'" label="Proyek" for="planner-project" :error="$errors->first('project_name')">
            <select id="planner-project" name="project_name" x-model="projectName" :disabled="type === 'content' || !branchId" class="crm-control bg-white disabled:bg-gray-100">
                <option value="">— Tanpa Proyek —</option>
                <template x-for="project in filteredProjects" :key="project.name"><option :value="project.name" x-text="project.name"></option></template>
            </select>
        </x-crm.field>
        <x-crm.field x-show="type !== 'content'" label="Visibilitas" for="planner-visibility" required :error="$errors->first('visibility')">
            <select id="planner-visibility" name="visibility" :disabled="type === 'content'" class="crm-control bg-white">
                <option value="team" {{ old('visibility', $item?->visibility ?? 'team') === 'team' ? 'selected' : '' }}>Tim Cabang</option>
                <option value="personal" {{ old('visibility', $item?->visibility) === 'personal' ? 'selected' : '' }}>Personal + PIC</option>
            </select>
        </x-crm.field>
        <template x-if="type === 'content'"><input type="hidden" name="visibility" value="team"></template>
        <x-crm.field label="Status" for="planner-status" required :error="$errors->first('status')">
            <select id="planner-status" name="status" x-model="status" class="crm-control bg-white">
                <template x-for="option in statusOptions[type]" :key="option.value"><option :value="option.value" x-text="option.label"></option></template>
            </select>
        </x-crm.field>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-crm.field label="Tanggal" for="planner-start-date" :error="$errors->first('start_date')">
            <div class="font-[Helvetica] font-bold text-xs uppercase mb-1" x-text="type === 'content' ? 'Tanggal Konten' : (type === 'agenda' ? 'Tanggal Mulai' : 'Tanggal Mulai')"></div>
            <div class="date-wrapper" data-accent="#b3bd95">
                <div class="date-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer flex justify-between" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div>
                <input id="planner-start-date" type="date" name="start_date" value="{{ old('start_date', $item?->start_date?->format('Y-m-d') ?? ($item?->item_type === 'content' ? $item?->scheduled_date?->format('Y-m-d') : null)) }}" :required="type === 'agenda' || type === 'content'" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
            </div>
        </x-crm.field>
        <x-crm.field x-show="type !== 'content'" label="Deadline" for="planner-deadline-date" required :error="$errors->first('deadline_date')">
            <div class="font-[Helvetica] font-bold text-xs uppercase mb-1" x-text="type === 'agenda' ? 'Tanggal Selesai' : (type === 'content' ? 'Jadwal Publikasi' : 'Deadline')"></div>
            <div class="date-wrapper" data-accent="#b3bd95">
                <div class="date-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer flex justify-between" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div>
                <input id="planner-deadline-date" type="date" name="deadline_date" value="{{ old('deadline_date', $item?->deadline_date?->format('Y-m-d')) }}" :required="type !== 'content'" :disabled="type === 'content'" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
            </div>
        </x-crm.field>
        <template x-if="type === 'agenda'">
            <div class="contents">
                <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Jam Mulai</label><x-crm.time-field name="start_time" :value="old('start_time', $item?->start_time)" required accent="#b3bd95" /></div>
                <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Jam Selesai</label><x-crm.time-field name="end_time" :value="old('end_time', $item?->end_time)" accent="#b3bd95" /></div>
            </div>
        </template>
    </div>

    <template x-if="type === 'task'">
        <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Prioritas</label><select name="priority" class="w-full border-2 border-black px-3 py-2 text-sm bg-white"><option value="low">Low</option><option value="medium" {{ old('priority', $item?->priority ?? 'medium') === 'medium' ? 'selected' : '' }}>Medium</option><option value="high" {{ old('priority', $item?->priority) === 'high' ? 'selected' : '' }}>High</option><option value="urgent" {{ old('priority', $item?->priority) === 'urgent' ? 'selected' : '' }}>Urgent</option></select></div>
    </template>

    <template x-if="type === 'agenda'">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Jenis Agenda</label><select name="agenda_type" x-model="agendaType" class="w-full border-2 border-black px-3 py-2 text-sm bg-white"><option value="visit">Kunjungan</option><option value="meeting">Meeting</option><option value="survey">Survey</option><option value="follow_up">Follow Up</option><option value="event">Event</option><option value="other">Lainnya</option></select></div>
            <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Lokasi</label><input name="location" value="{{ old('location', $item?->location) }}" class="w-full border-2 border-black px-3 py-2 text-sm"></div>
        </div>
    </template>

    <template x-if="type === 'content'">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tujuan Konten</label><select name="tujuan_konten" x-model="tujuanKonten" class="w-full border-2 border-black px-3 py-2 text-sm bg-white"><option value="Edukasi">Edukasi</option><option value="Entertainment">Entertainment</option><option value="Inspirasi">Inspirasi</option></select></div>
            <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Platform</label><select name="platform" x-model="platform" class="w-full border-2 border-black px-3 py-2 text-sm bg-white"><option value="Sosial Media">Sosial Media</option><option value="Website">Website</option></select></div>
            <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Format Konten</label><select name="content_format" x-model="contentFormat" class="w-full border-2 border-black px-3 py-2 text-sm bg-white"><option value="Video">Video</option><option value="Gambar">Gambar</option><option value="Video Karosel">Video Karosel</option><option value="Karosel">Karosel</option><option value="Artikel">Artikel</option></select></div>
        </div>
    </template>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" x-show="type !== 'content'">
        <div>
            <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">PIC Akun Oasis</label>
            <select name="assigned_user_ids[]" multiple size="5" :disabled="type === 'content'" class="w-full border-2 border-black px-3 py-2 text-sm bg-white">
                <template x-for="user in filteredUsers" :key="user.id"><option :value="user.id" x-text="user.name" :selected="selectedUserIds.includes(user.id)"></option></template>
            </select>
            <p class="text-[11px] mt-1 font-['Times_New_Roman']">Gunakan Ctrl/Cmd untuk memilih beberapa akun.</p>
        </div>
        <div>
            <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">PIC Eksternal</label>
            <div class="flex gap-2"><input x-model="draftPic" @keydown.enter.prevent="addExternalPic()" placeholder="Nama PIC di luar Oasis" class="grow border-2 border-black px-3 py-2 text-sm"><button type="button" @click="addExternalPic()" class="border-2 border-black bg-black text-white px-4 font-bold">+</button></div>
            <div class="flex flex-wrap gap-2 mt-2"><template x-for="(name,index) in externalPics" :key="name"><span class="border-2 border-black bg-[#b3bd95] px-2 py-1 text-xs font-bold"><span x-text="name"></span><button type="button" @click="externalPics.splice(index,1)" class="ml-1">×</button><input type="hidden" name="pic_names[]" :value="name"></span></template></div>
        </div>
    </div>

    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1" x-text="type === 'content' ? 'Catatan' : 'Catatan / Progress'"></label><textarea name="notes" rows="4" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']">{{ old('notes', $item?->notes) }}</textarea></div>

    @if($errors->any())
        <x-crm.alert variant="error" title="Data belum tersimpan">
            Periksa kembali bidang yang ditandai. {{ $errors->first() }}
        </x-crm.alert>
    @endif
    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <x-crm.button variant="secondary" :href="route('content-calendar.index', ['view' => request('view', 'today')])">Batal</x-crm.button>
        <x-crm.button type="submit" variant="primary" accent="planner">{{ $editing ? 'Simpan Perubahan' : 'Simpan Item' }}</x-crm.button>
    </div>
</form>

@push('scripts')
<script>
function plannerForm(config) {
    return {
        type: config.type,
        branchId: config.branchId,
        projectName: config.projectName || '',
        status: config.status,
        agendaType: config.agendaType,
        tujuanKonten: config.tujuanKonten,
        platform: config.platform,
        contentFormat: config.contentFormat,
        projects: config.projects,
        users: config.users,
        externalPics: Array.isArray(config.externalPics) ? config.externalPics : [],
        selectedUserIds: @json(array_map('intval', $selectedUsers)),
        draftPic: '',
        typeOptions: [
            { value:'task', label:'TASK', active:'bg-[#9ab6c8]' },
            { value:'agenda', label:'AGENDA', active:'bg-[#e6915d]' },
            { value:'content', label:'KONTEN', active:'bg-[#8c9ae0] text-white' },
        ],
        statusOptions: {
            task: [{value:'todo',label:'To Do'},{value:'in_progress',label:'In Progress'},{value:'completed',label:'Completed'},{value:'lost_track',label:'Lost Track'}],
            agenda: [{value:'planned',label:'Planned'},{value:'confirmed',label:'Confirmed'},{value:'done',label:'Done'},{value:'cancelled',label:'Cancelled'},{value:'rescheduled',label:'Dijadwalkan Ulang'}],
            content: [{value:'idea',label:'Ide'},{value:'content_in_progress',label:'Dalam Proses'},{value:'done_editing',label:'Selesai Edit'},{value:'uploaded',label:'Di Upload'}],
        },
        get filteredProjects() { return this.projects.filter(project => String(project.branch_id) === String(this.branchId)); },
        get filteredUsers() { return this.users.filter(user => (user.branch_ids || []).includes(String(this.branchId))); },
        changeType(type) { this.type = type; this.status = this.statusOptions[type][0].value; },
        addExternalPic() { const value = this.draftPic.trim(); if (value && !this.externalPics.includes(value)) this.externalPics.push(value); this.draftPic = ''; },
    };
}
</script>
@endpush
