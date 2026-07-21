@php
    $existingMemberships = isset($user) ? $user->branches->keyBy('id') : collect();
    $selectedBranchIds = array_map('intval', old('branch_ids', $existingMemberships->keys()->all()));
    $oldPermissions = old('membership_permissions', []);
@endphp

<div>
    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Akses Cabang</label>
    <p class="text-xs font-['Times_New_Roman'] mb-2">Cabang utama otomatis ikut dipilih. View selalu aktif untuk membership terpilih.</p>
    <div class="border-2 border-black divide-y-2 divide-black">
        @foreach($branches as $branch)
            @php
                $membership = $existingMemberships->get($branch->id)?->pivot;
                $permissions = $oldPermissions[$branch->id] ?? [];
                $selected = in_array((int) $branch->id, $selectedBranchIds, true);
            @endphp
            <div class="px-3 py-2 bg-white">
                <label class="flex items-center gap-2 font-[Helvetica] font-bold text-sm">
                    <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" {{ $selected ? 'checked' : '' }}>
                    <span>{{ $branch->name }} ({{ $branch->code }})</span>
                </label>
                <div class="ml-6 mt-2 flex flex-wrap gap-4 text-xs font-[Helvetica]">
                    <label><input type="checkbox" name="membership_permissions[{{ $branch->id }}][can_edit]" value="1" {{ ($permissions['can_edit'] ?? $membership?->can_edit) ? 'checked' : '' }}> Edit</label>
                    <label><input type="checkbox" name="membership_permissions[{{ $branch->id }}][can_sync]" value="1" {{ ($permissions['can_sync'] ?? $membership?->can_sync) ? 'checked' : '' }}> Sync</label>
                    <label><input type="checkbox" name="membership_permissions[{{ $branch->id }}][can_manage_members]" value="1" {{ ($permissions['can_manage_members'] ?? $membership?->can_manage_members) ? 'checked' : '' }}> Kelola Anggota</label>
                </div>
            </div>
        @endforeach
    </div>
    @error('branch_ids')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
    @error('branch_ids.*')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
</div>
