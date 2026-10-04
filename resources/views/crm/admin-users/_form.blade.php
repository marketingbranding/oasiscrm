@php
    $value = fn (string $key, mixed $default = null) => old($key, isset($user) ? data_get($user, $key, $default) : $default);
    $selectedBranchIdsForUi = array_map('strval', array_map('intval', old('branch_ids', isset($user) ? $user->branches->pluck('id')->all() : [])));
    $selectedProjectIdsForUi = array_map('strval', array_map('intval', old('assigned_project_ids', isset($user) ? $user->assignedProjects->pluck('id')->all() : [])));
    $selectedRole = $roles->firstWhere('id', (int) $value('role_id'));
@endphp
<div x-data="{
    roleSlug: @js($selectedRole?->slug),
    primaryBranchId: @js((string) $value('branch_id', '')),
    branchSearch: '',
    projectSearch: '',
    selectedBranchIds: @js($selectedBranchIdsForUi),
    selectedProjectIds: @js($selectedProjectIdsForUi),
    matchesOption(label, query) { return !query.trim() || label.toLowerCase().includes(query.toLowerCase()); },
    projectMatchesBranch(branchId) { return [this.primaryBranchId, ...this.selectedBranchIds].filter(Boolean).includes(String(branchId)); },
    isSalesWorkspace() { return ['sales', 'sales_coordinator'].includes(this.roleSlug); },
    workspaceLabel() { return this.isSalesWorkspace() ? 'Workspace Sales' : 'Workspace Organisasi'; },
    workspaceHint() { return this.isSalesWorkspace() ? 'Sales menggunakan cabang dan proyek untuk menentukan ruang kerja operasional.' : 'Cabang dan proyek menentukan konteks organisasi yang dapat dikelola pengguna.'; }
}">
<div class="grid gap-4 md:grid-cols-2">
    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Lengkap</label><input name="name" value="{{ $value('name') }}" required class="w-full border-2 border-black px-3 py-2 text-sm rounded-none">@error('name')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror</div>
    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Email</label><input type="email" name="email" value="{{ $value('email') }}" required class="w-full border-2 border-black px-3 py-2 text-sm rounded-none">@error('email')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror</div>
    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Telepon</label><input name="phone" value="{{ $value('phone') }}" class="w-full border-2 border-black px-3 py-2 text-sm rounded-none">@error('phone')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror</div>
    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Peran</label><select name="role_id" required @change="roleSlug = $event.target.selectedOptions[0]?.dataset.slug || ''" class="w-full border-2 border-black px-3 py-2 text-sm bg-white rounded-none"><option value="">Pilih peran</option>@foreach($roles as $role)<option value="{{ $role->id }}" data-slug="{{ $role->slug }}" @selected((int) $value('role_id') === (int) $role->id)>{{ $role->name }}</option>@endforeach</select>@error('role_id')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror</div>
    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang Utama</label><select name="branch_id" required x-model="primaryBranchId" class="w-full border-2 border-black px-3 py-2 text-sm bg-white rounded-none"><option value="">Pilih cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) $value('branch_id') === (int) $branch->id)>{{ $branch->name }} ({{ $branch->code }})</option>@endforeach</select>@error('branch_id')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror</div>
    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Atasan Langsung</label><select name="supervisor_user_id" class="w-full border-2 border-black px-3 py-2 text-sm bg-white rounded-none"><option value="">Tidak ada</option>@foreach($supervisors as $supervisor)@if(!isset($user) || !$supervisor->is($user))<option value="{{ $supervisor->id }}" @selected((int) $value('supervisor_user_id') === (int) $supervisor->id)>{{ $supervisor->name }} - {{ $supervisor->role?->name }}</option>@endif @endforeach</select>@error('supervisor_user_id')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror</div>
</div>
<div class="mt-4 flex items-start gap-3 border-2 border-black bg-[#f5f0df] p-3 text-sm"><span class="mt-0.5 border-2 border-black bg-[var(--oasis-yellow)] px-2 py-1 font-[Helvetica] text-[10px] font-bold uppercase" x-text="workspaceLabel()"></span><p class="font-['Times_New_Roman']" x-text="workspaceHint()"></p></div>
@include('crm.admin-users._memberships')
@include('crm.admin-users._project-assignments')
</div>
