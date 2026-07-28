@php
    $selectedProjects = array_map('intval', old('assigned_project_ids', isset($user) ? $user->assignedProjects->pluck('id')->all() : []));
    $existingPrimary = isset($user) ? $user->assignedProjects->first(fn ($project) => (bool) $project->pivot->is_primary)?->id : null;
    $primaryProject = old('primary_project_id', $existingPrimary);
@endphp
<div>
    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek Utama</label>
    <select name="primary_project_id" class="w-full border-2 border-black px-3 py-2 text-sm bg-white rounded-none">
        <option value="">Tidak ada</option>
        @foreach($projects as $project)<option value="{{ $project->id }}" @selected((int) $primaryProject === (int) $project->id)>{{ $project->project_name }} - {{ $project->branch?->name }}</option>@endforeach
    </select>
    @error('primary_project_id')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
</div>
<div>
    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek Tambahan</label>
    <div class="border-2 border-black p-3 grid gap-2 sm:grid-cols-2 max-h-56 overflow-y-auto">
        @foreach($projects as $project)<label class="flex items-center gap-2 text-sm font-['Times_New_Roman']"><input type="checkbox" name="assigned_project_ids[]" value="{{ $project->id }}" @checked(in_array((int) $project->id, $selectedProjects, true))> {{ $project->project_name }} ({{ $project->branch?->code }})</label>@endforeach
    </div>
    @error('assigned_project_ids')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
    @error('assigned_project_ids.*')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
</div>
