@php
    $selectedProjects = array_map('intval', old('assigned_project_ids', isset($user) ? $user->assignedProjects->pluck('id')->all() : []));
    $existingPrimary = isset($user) ? $user->assignedProjects->first(fn ($project) => (bool) $project->pivot->is_primary)?->id : null;
    $primaryProject = old('primary_project_id', $existingPrimary);
@endphp
<section class="border-t-2 border-black pt-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><h2 class="font-[Helvetica] font-bold text-xs uppercase" x-text="isSalesWorkspace() ? 'Workspace proyek Sales' : 'Penugasan proyek'"></h2><p class="mt-1 text-xs text-gray-600">Pilih proyek utama bila pengguna perlu konteks default.</p></div>
        <label class="w-full sm:w-56"><span class="sr-only">Cari proyek</span><input type="search" x-model="projectSearch" placeholder="Cari proyek atau cabang" class="w-full border-2 border-black px-3 py-2 text-sm"></label>
    </div>
    <div class="mt-3">
    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek Utama</label>
    <select name="primary_project_id" x-model="primaryProjectId" class="w-full border-2 border-black px-3 py-2 text-sm bg-white rounded-none">
        <option value="">Tidak ada</option>
        @foreach($projects as $project)<option value="{{ $project->id }}" @selected((int) $primaryProject === (int) $project->id)>{{ $project->project_name }} - {{ $project->branch?->name }}</option>@endforeach
    </select>
    @error('primary_project_id')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
</div>
    <div class="mt-4">
    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek Tambahan</label>
    <div class="flex max-h-56 flex-wrap gap-2 overflow-y-auto border-2 border-black p-3">
        @foreach($projects as $project)<label data-search="{{ strtolower($project->project_name.' '.($project->branch?->name ?? '').' '.($project->branch?->code ?? '')) }}" data-branch-id="{{ $project->branch_id }}" x-show="projectMatchesBranch($el.dataset.branchId) && matchesOption($el.dataset.search, projectSearch)" class="crm-choice-chip"><input type="checkbox" name="assigned_project_ids[]" value="{{ $project->id }}" x-model="selectedProjectIds" @checked(in_array((int) $project->id, $selectedProjects, true))><span>{{ $project->project_name }} <small>({{ $project->branch?->code }})</small></span></label>@endforeach
    </div>
    <p class="mt-2 text-xs text-gray-600"><span x-text="selectedProjectIds.length"></span> proyek dipilih</p>
    @error('assigned_project_ids')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
    @error('assigned_project_ids.*')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
    </div>
    </div>
</section>
