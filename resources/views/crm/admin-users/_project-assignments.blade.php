@php
    $existingProjects = isset($user) ? $user->assignedProjects->keyBy('id') : collect();
    $selectedProjectIds = array_map('intval', old('assigned_project_ids', $existingProjects->keys()->all()));
    $existingPrimary = $existingProjects->first(fn ($project) => (bool) $project->pivot->is_primary)?->id;
    $primaryProjectId = old('primary_project_id', $existingPrimary);
@endphp

<div x-show="role === 'sales'" x-cloak>
    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek Sales</label>
    <p class="text-xs font-['Times_New_Roman'] mb-2">Pilih minimal satu proyek aktif dari cabang yang dapat diakses. Proyek utama bersifat opsional dan hanya dapat dipilih satu.</p>
    <label class="inline-flex items-center gap-1 mb-2 text-xs font-[Helvetica]">
        <input type="radio" name="primary_project_id" value="" :disabled="role !== 'sales'"
            {{ blank($primaryProjectId) ? 'checked' : '' }}>
        Tanpa proyek utama
    </label>
    <div class="border-2 border-black divide-y-2 divide-black">
        @forelse($branches as $branch)
            @php($branchProjects = $projectsByBranch->get($branch->id, collect()))
            @if($branchProjects->isNotEmpty())
                <div class="bg-white">
                    <div class="px-3 py-1.5 bg-gray-100 font-[Helvetica] font-bold text-xs uppercase border-b-2 border-black">
                        {{ $branch->name }} ({{ $branch->code }})
                    </div>
                    <div class="divide-y divide-gray-300">
                        @foreach($branchProjects as $project)
                            <div class="px-3 py-2 flex items-center justify-between gap-4 text-sm">
                                <label class="flex items-center gap-2 font-['Times_New_Roman']">
                                    <input type="checkbox" name="assigned_project_ids[]" value="{{ $project->id }}" :disabled="role !== 'sales'"
                                        {{ in_array((int) $project->id, $selectedProjectIds, true) ? 'checked' : '' }}>
                                    <span>{{ $project->project_name }}</span>
                                </label>
                                <label class="flex items-center gap-1 text-xs font-[Helvetica] whitespace-nowrap">
                                    <input type="radio" name="primary_project_id" value="{{ $project->id }}" :disabled="role !== 'sales'"
                                        {{ (int) $primaryProjectId === (int) $project->id ? 'checked' : '' }}>
                                    Utama
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @empty
            <div class="px-3 py-4 text-sm font-['Times_New_Roman']">Belum ada cabang aktif.</div>
        @endforelse
    </div>
    @error('assigned_project_ids')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
    @error('assigned_project_ids.*')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
    @error('primary_project_id')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
</div>
