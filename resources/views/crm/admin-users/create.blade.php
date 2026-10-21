@extends('layouts.crm')

@section('title', 'Buat Pengguna - Oasis CRM')

@php
    $roleOptions = $roles->map(fn ($role) => [
        'id' => $role->id,
        'name' => $role->name,
        'slug' => $role->slug,
        'description' => $role->description,
    ])->values();
    $branchOptions = $branches->map(fn ($branch) => [
        'id' => $branch->id,
        'name' => $branch->name,
        'code' => $branch->code,
    ])->values();
    $projectOptions = $projects->map(fn ($project) => [
        'id' => $project->id,
        'project_name' => $project->project_name,
        'branch_id' => $project->branch_id,
        'branch' => $project->branch ? ['name' => $project->branch->name, 'code' => $project->branch->code] : null,
    ])->values();
    $supervisorOptions = $supervisors->map(fn ($supervisor) => [
        'id' => $supervisor->id,
        'name' => $supervisor->name,
        'branch_id' => $supervisor->branch_id,
        'role' => $supervisor->role ? ['slug' => $supervisor->role->slug, 'name' => $supervisor->role->name] : null,
    ])->values();
    $coordinatorOptions = $coordinators->map(fn ($coordinator) => [
        'id' => $coordinator->id,
        'name' => $coordinator->name,
        'branch_id' => $coordinator->branch_id,
        'branch' => $coordinator->branch ? ['name' => $coordinator->branch->name, 'code' => $coordinator->branch->code] : null,
        'current_coordinator_sales' => $coordinator->currentCoordinatorSales->map(fn ($sales) => ['id' => $sales->id, 'name' => $sales->name])->values(),
    ])->values();
    $assignmentErrors = ['branch_id', 'branch_ids', 'branch_ids.*', 'primary_project_id', 'assigned_project_ids', 'assigned_project_ids.*', 'supervisor_user_id', 'coordinator_user_id'];
    $activationErrors = ['provisioning_mode', 'temporary_password', 'temporary_password_confirmation', 'submit_action'];
    $initialStep = $errors->hasAny($activationErrors) ? 3 : ($errors->hasAny($assignmentErrors) ? 2 : 1);
@endphp

@section('content')
<x-crm.page-header
    variant="canonical"
    eyebrow="Administrasi Pengguna"
    title="Buat Pengguna"
    description="Tempatkan orang baru ke dalam struktur organisasi OASIS, lalu tinjau aksesnya sebelum akun dibuat."
/>

<form
    method="POST"
    action="{{ route('admin-users.store') }}"
    class="space-y-5"
    x-data="adminUserCreation({
        initialStep: @js($initialStep),
        roles: @js($roleOptions),
        branches: @js($branchOptions),
        projects: @js($projectOptions),
        supervisors: @js($supervisorOptions),
        coordinators: @js($coordinatorOptions),
        name: @js(old('name', '')),
        email: @js(old('email', '')),
        phone: @js(old('phone', '')),
        roleId: @js(old('role_id', '')),
        primaryBranchId: @js(old('branch_id', '')),
        additionalBranchIds: @js(old('branch_ids', [])),
        primaryProjectId: @js(old('primary_project_id', '')),
        additionalProjectIds: @js(old('assigned_project_ids', [])),
        supervisorId: @js(old('supervisor_user_id', '')),
        coordinatorId: @js(old('coordinator_user_id', '')),
        provisioningMode: @js(old('provisioning_mode', 'invitation'))
    })"
    x-init="step = initialStep"
    novalidate
>
    @csrf

    <div class="flex flex-wrap items-center gap-2" aria-label="Tris langkah pembuatan pengguna">
        <template x-for="item in [{ number: 1, label: 'Identitas & Peran' }, { number: 2, label: 'Penempatan Organisasi' }, { number: 3, label: 'Review & Aktivasi' }]" :key="item.number">
            <button
                type="button"
                class="flex min-h-11 items-center gap-2 border-2 px-3 py-2 text-left text-xs font-bold transition"
                :class="step === item.number ? 'border-black bg-[#8c9ae0] text-black' : (step > item.number ? 'border-[#39734c] bg-[#e8f2e9] text-[#245b35]' : 'border-[#cfcdc4] bg-white text-[#66645f]')"
                :aria-current="step === item.number ? 'step' : undefined"
                @click="goToStep(item.number)"
            >
                <span class="flex h-6 w-6 items-center justify-center border border-current" x-text="item.number"></span>
                <span x-text="item.label"></span>
            </button>
        </template>
    </div>

    <div x-cloak x-show="errorSummary" class="border-2 border-[#c0392b] bg-[#fff1f0] px-4 py-3 text-sm font-bold text-[#8f261d]" role="alert" x-text="errorSummary"></div>
    @if($errors->any())
        <div class="border-2 border-[#c0392b] bg-[#fff1f0] px-4 py-3 text-sm text-[#8f261d]" role="alert">
            <strong class="block font-bold">Periksa kembali data pada langkah ini.</strong>
            <ul class="mt-1 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section x-cloak x-show="step === 1" class="space-y-5" aria-labelledby="user-step-one-title">
        <div class="crm-section border-2 border-black bg-white">
            <div class="border-b-2 border-black bg-black px-4 py-3 text-xs font-bold uppercase tracking-wide text-white">
                <h2 id="user-step-one-title">Identitas &amp; Peran</h2>
                <p class="mt-1 font-normal normal-case tracking-normal text-[#dedcd4]">Mulai dari siapa orangnya dan peran utama yang akan dijalankan.</p>
            </div>
            <div class="grid gap-4 p-4 md:grid-cols-2">
                <x-crm.field label="Nama Lengkap" for="name" required :error="$errors->first('name')">
                    <input id="name" name="name" x-model="name" required autocomplete="name" class="crm-input w-full" />
                </x-crm.field>
                <x-crm.field label="Email" for="email" required :error="$errors->first('email')">
                    <input id="email" name="email" type="email" x-model="email" required autocomplete="email" class="crm-input w-full" />
                </x-crm.field>
                <x-crm.field label="Telepon" for="phone" :error="$errors->first('phone')">
                    <input id="phone" name="phone" x-model="phone" autocomplete="tel" class="crm-input w-full" />
                </x-crm.field>
                <x-crm.field label="Peran" for="role_id" required :error="$errors->first('role_id')">
                    <select id="role_id" name="role_id" required class="crm-input w-full" x-model="roleId" @change="setRole($event.target.value)">
                        <option value="">Pilih peran</option>
                        @foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach
                    </select>
                </x-crm.field>
            </div>
        </div>

        <div x-cloak x-show="roleId" class="flex items-start gap-3 border border-[#cfcdc4] bg-[#f8f7f2] px-4 py-3" role="status">
            <span class="mt-0.5 border border-[#2f5db7] px-1.5 py-0.5 text-[10px] font-bold uppercase text-[#2f5db7]">Role context</span>
            <p class="text-sm" x-text="roleDescription"></p>
        </div>
    </section>

    <section x-cloak x-show="step === 2" class="space-y-5" aria-labelledby="user-step-two-title">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(18rem,0.75fr)]">
            <div class="space-y-5">
                <div class="border-2 border-black bg-white">
                    <div class="border-b-2 border-black bg-black px-4 py-3 text-xs font-bold uppercase tracking-wide text-white">
                        <h2 id="user-step-two-title">Penempatan Organisasi</h2>
                        <p class="mt-1 font-normal normal-case tracking-normal text-[#dedcd4]">Pilihan di sini menentukan posisi orang baru dalam ruang kerja.</p>
                    </div>
                    <div class="space-y-5 p-4">
                        <x-crm.field label="Cabang Utama" for="branch_id" :required="true" :error="$errors->first('branch_id')" hint="Cabang utama menjadi titik awal penyaringan struktur dan proyek.">
                            <select id="branch_id" name="branch_id" class="crm-input w-full" x-model="primaryBranchId" @change="setPrimaryBranch($event.target.value)" :required="requiresBranch">
                                <option value="">Pilih cabang utama</option>
                                @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }} ({{ $branch->code }})</option>@endforeach
                            </select>
                        </x-crm.field>

                        <div x-cloak x-show="requiresBranch" class="border border-[#cfcdc4] bg-[#f8f7f2] p-3">
                            <button type="button" class="flex min-h-11 w-full items-center justify-between text-left text-xs font-bold uppercase" @click="additionalAccessOpen = !additionalAccessOpen" :aria-expanded="additionalAccessOpen.toString()">
                                <span>Akses Tambahan</span><span aria-hidden="true" x-text="additionalAccessOpen ? '−' : '+'"></span>
                            </button>
                            <div x-cloak x-show="additionalAccessOpen" class="mt-3 space-y-4 border-t border-[#cfcdc4] pt-3">
                                <div>
                                    <label for="branch-search" class="mb-1 block text-xs font-bold uppercase">Tambah Cabang</label>
                                    <input id="branch-search" type="search" x-model="branchSearch" placeholder="Cari cabang..." class="crm-input w-full" />
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="branch in selectedAdditionalBranches" :key="`branch-chip-${branch.id}`">
                                        <button type="button" class="crm-filter-chip" @click="removeAdditionalBranch(branch.id)"><span x-text="branch.name"></span> <span aria-hidden="true">×</span></button>
                                    </template>
                                    <span x-show="selectedAdditionalBranches.length === 0" class="text-sm text-[#66645f]">Belum ada cabang tambahan.</span>
                                </div>
                                <div class="grid max-h-52 gap-2 overflow-y-auto sm:grid-cols-2">
                                    <template x-for="branch in filteredBranches" :key="`branch-option-${branch.id}`">
                                        <label class="flex min-h-11 items-center gap-2 border border-[#cfcdc4] bg-white px-3 py-2 text-sm">
                                            <input type="checkbox" :value="branch.id" :checked="isAdditionalBranchSelected(branch.id)" @change="toggleAdditionalBranch(branch.id)" />
                                            <span><span x-text="branch.name"></span> <span class="text-xs text-[#66645f]" x-text="`(${branch.code})`"></span></span>
                                        </label>
                                    </template>
                                </div>
                                <template x-for="branchId in additionalBranchIds" :key="`branch-hidden-${branchId}`"><input type="hidden" name="branch_ids[]" :value="branchId" /></template>
                            </div>
                        </div>

                        <div x-cloak x-show="showReporting">
                            <x-crm.field label="Pelaporan Ke" for="supervisor_user_id" :error="$errors->first('supervisor_user_id')" hint="Relasi pelaporan tidak digunakan sebagai sumber otorisasi Team Sales.">
                                <select id="supervisor_user_id" name="supervisor_user_id" class="crm-input w-full" x-model="supervisorId">
                                    <option value="">Tidak ada</option>
                                    <template x-for="supervisor in visibleSupervisors" :key="`supervisor-${supervisor.id}`"><option :value="supervisor.id" x-text="`${supervisor.name} — ${supervisor.role?.name ?? ''}`"></option></template>
                                </select>
                            </x-crm.field>
                        </div>

                        <div x-cloak x-show="roleSlug === 'sales'" class="space-y-4 border-t-2 border-black pt-4">
                            <x-crm.field label="Koordinator Tim" for="coordinator_user_id" required :error="$errors->first('coordinator_user_id')" hint="Mapping Team memakai sales_coordinator_sales dan harus berada pada cabang yang sama.">
                                <select id="coordinator_user_id" name="coordinator_user_id" class="crm-input w-full" x-model="coordinatorId" :required="roleSlug === 'sales'">
                                    <option value="">Pilih Koordinator Tim</option>
                                    <template x-for="coordinator in visibleCoordinators" :key="`coordinator-${coordinator.id}`"><option :value="coordinator.id" x-text="`${coordinator.name} — ${coordinator.branch?.name ?? ''}`"></option></template>
                                </select>
                                <p x-show="primaryBranchId && visibleCoordinators.length === 0" class="mt-1 text-xs font-bold text-[#c0392b]">Belum ada Sales Coordinator aktif di cabang ini.</p>
                            </x-crm.field>

                            <x-crm.field label="Proyek Utama" for="primary_project_id" :error="$errors->first('primary_project_id')" hint="Opsional di form; saat disimpan, Sales tetap mengikuti validasi assignment proyek yang berlaku.">
                                <select id="primary_project_id" name="primary_project_id" class="crm-input w-full" x-model="primaryProjectId">
                                    <option value="">Pilih proyek utama</option>
                                    <template x-for="project in filteredPrimaryProjects" :key="`primary-project-${project.id}`"><option :value="project.id" x-text="`${project.project_name} — ${project.branch?.name ?? ''}`"></option></template>
                                </select>
                                <p x-show="primaryBranchId && filteredPrimaryProjects.length === 0" class="mt-1 text-xs font-bold text-[#c0392b]">Cabang ini belum memiliki proyek yang dapat dipilih.</p>
                            </x-crm.field>

                            <div class="border border-[#cfcdc4] bg-[#f8f7f2] p-3">
                                <div class="mb-3 flex items-center justify-between gap-3"><div><h3 class="text-xs font-bold uppercase">Proyek Tambahan</h3><p class="text-xs text-[#66645f]">Opsional, gunakan pencarian untuk akses lintas proyek yang sah.</p></div><input type="search" aria-label="Cari proyek tambahan" placeholder="Cari..." x-model="projectSearch" class="crm-input w-40" /></div>
                                <div class="mb-3 flex flex-wrap gap-2"><template x-for="project in selectedAdditionalProjects" :key="`project-chip-${project.id}`"><button type="button" class="crm-filter-chip" @click="removeAdditionalProject(project.id)"><span x-text="project.project_name"></span> <span aria-hidden="true">×</span></button></template><span x-show="selectedAdditionalProjects.length === 0" class="text-sm text-[#66645f]">Belum ada proyek tambahan.</span></div>
                                <div class="grid max-h-48 gap-2 overflow-y-auto sm:grid-cols-2"><template x-for="project in filteredAdditionalProjects" :key="`project-option-${project.id}`"><label class="flex min-h-11 items-center gap-2 border border-[#cfcdc4] bg-white px-3 py-2 text-sm"><input type="checkbox" :value="project.id" :checked="isAdditionalProjectSelected(project.id)" @change="toggleAdditionalProject(project.id)" /><span x-text="project.project_name"></span></label></template></div>
                                <template x-for="projectId in additionalProjectIds" :key="`project-hidden-${projectId}`"><input type="hidden" name="assigned_project_ids[]" :value="projectId" /></template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="h-fit border-2 border-black bg-[#f8f7f2] lg:sticky lg:top-4" aria-labelledby="organization-preview-title">
                <div class="border-b-2 border-black bg-[#b3bd95] px-4 py-3"><h2 id="organization-preview-title" class="text-xs font-bold uppercase">Preview Struktur</h2><p class="mt-1 text-xs">Posisi user baru akan terlihat di sini.</p></div>
                <div class="space-y-3 p-4 text-sm">
                    <p x-show="!primaryBranchId" class="border border-dashed border-[#cfcdc4] bg-white px-3 py-4 text-[#66645f]">Pilih cabang untuk melihat struktur organisasi.</p>
                    <template x-if="primaryBranchId">
                        <div class="space-y-3">
                            <div class="border-2 border-black bg-white px-3 py-2 font-bold" x-text="selectedBranch?.name ?? 'Cabang terpilih'"></div>
                            <div class="ml-4 border-l-2 border-[#111] pl-4"><div class="border border-[#cfcdc4] bg-white px-3 py-2"><span class="block text-[10px] font-bold uppercase text-[#66645f]">Struktur cabang</span><span>Branch Manager</span></div></div>
                            <div class="ml-8 border-l-2 border-[#111] pl-4"><div class="border border-[#cfcdc4] bg-white px-3 py-2"><span class="block text-[10px] font-bold uppercase text-[#66645f]">Pelaporan</span><span x-text="supervisorId ? (visibleSupervisors.find(item => String(item.id) === String(supervisorId))?.name ?? 'Pelaporan dipilih') : 'Supervisor / pelaporan sesuai penempatan'"></span></div></div>
                            <template x-if="roleSlug === 'sales' && selectedCoordinator"><div class="ml-12 border-l-2 border-[#111] pl-4"><div class="border border-[#cfcdc4] bg-white px-3 py-2"><span class="block text-[10px] font-bold uppercase text-[#66645f]">Koordinator Tim</span><span x-text="selectedCoordinator.name"></span></div><div class="mt-2 space-y-2"><template x-for="sales in visibleTeamSales" :key="`team-sales-${sales.id}`"><div class="border border-[#dedcd4] bg-white px-3 py-2 text-xs" x-text="sales.name"></div></template><div class="border-2 border-[#2f5db7] bg-[#eaf0ff] px-3 py-2 text-xs font-bold">USER BARU <span class="font-normal" x-text="role?.name ?? 'User'"></span></div><p x-show="remainingTeamSales > 0" class="text-xs text-[#66645f]" x-text="`+ ${remainingTeamSales} Sales lainnya`"></p></div></div></template>
                            <template x-if="['sas', 'sam'].includes(roleSlug)"><div class="ml-12 border-l-2 border-[#111] pl-4"><div class="border border-[#cfcdc4] bg-white px-3 py-2"><span class="block text-[10px] font-bold uppercase text-[#66645f]">Administrasi / Monitoring Cabang</span><span class="font-bold" x-text="role?.name"></span><span class="mt-1 block text-xs text-[#66645f]">Bukan anggota Team Sales.</span></div></div></template>
                        </div>
                    </template>
                </div>
            </aside>
        </div>
    </section>

    <section x-cloak x-show="step === 3" class="space-y-5" aria-labelledby="user-step-three-title">
        <div class="border-2 border-black bg-white">
            <div class="border-b-2 border-black bg-black px-4 py-3 text-xs font-bold uppercase tracking-wide text-white"><h2 id="user-step-three-title">Review &amp; Aktivasi</h2><p class="mt-1 font-normal normal-case tracking-normal text-[#dedcd4]">Pastikan penempatan organisasi sudah masuk akal sebelum menyimpan akun.</p></div>
            <div class="grid gap-4 p-4 md:grid-cols-2">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 border border-[#cfcdc4] bg-[#f8f7f2] p-4 text-sm"><dt class="font-bold">Nama</dt><dd x-text="name || '—'"></dd><dt class="font-bold">Email</dt><dd x-text="email || '—'"></dd><dt class="font-bold">Telepon</dt><dd x-text="phone || '—'"></dd><dt class="font-bold">Peran</dt><dd x-text="role?.name || '—'"></dd><dt class="font-bold">Cabang</dt><dd x-text="selectedBranch?.name || 'Tidak ada'"></dd><dt class="font-bold">Team</dt><dd x-text="selectedCoordinator?.name || 'Tidak ada'"></dd><dt class="font-bold">Proyek utama</dt><dd x-text="filteredPrimaryProjects.find(project => String(project.id) === String(primaryProjectId))?.project_name || 'Tidak ada'"></dd><dt class="font-bold">Scope</dt><dd x-text="reviewScope"></dd><dt class="font-bold">Akses tambahan</dt><dd x-text="`${selectedAdditionalBranches.length} cabang, ${selectedAdditionalProjects.length} proyek`"></dd></dl>
                <div class="space-y-4">
                    <fieldset class="space-y-3"><legend class="text-xs font-bold uppercase">Aktivasi Akun</legend><label class="flex cursor-pointer items-start gap-3 border-2 px-4 py-3" :class="provisioningMode === 'invitation' ? 'border-[#2f5db7] bg-[#eaf0ff]' : 'border-[#cfcdc4] bg-white'"><input type="radio" name="provisioning_mode" value="invitation" x-model="provisioningMode" class="mt-1" /><span><strong class="block text-sm">Kirim Undangan</strong><span class="mt-1 block text-xs text-[#66645f]">User melakukan aktivasi melalui email dan membuat password sendiri.</span></span></label>@if(Auth::user()->isSuperadmin())<label class="flex cursor-pointer items-start gap-3 border-2 px-4 py-3" :class="provisioningMode === 'direct' ? 'border-[#2f5db7] bg-[#eaf0ff]' : 'border-[#cfcdc4] bg-white'"><input type="radio" name="provisioning_mode" value="direct" x-model="provisioningMode" class="mt-1" /><span><strong class="block text-sm">Aktifkan Langsung</strong><span class="mt-1 block text-xs text-[#66645f]">Superadmin membuat akun aktif dengan proses aktivasi langsung sesuai existing application rules.</span></span></label><div x-cloak x-show="provisioningMode === 'direct'" class="space-y-3 border-l-2 border-[#2f5db7] pl-4"><div><label for="temporary_password" class="mb-1 block text-xs font-bold uppercase">Password Sementara</label><x-password-input id="temporary_password" name="temporary_password" x-bind:required="provisioningMode === 'direct'" autocomplete="new-password" class="crm-input w-full" />@error('temporary_password')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror</div><div><label for="temporary_password_confirmation" class="mb-1 block text-xs font-bold uppercase">Konfirmasi Password</label><x-password-input id="temporary_password_confirmation" name="temporary_password_confirmation" x-bind:required="provisioningMode === 'direct'" autocomplete="new-password" class="crm-input w-full" /></div></div>@endif</fieldset>
                </div>
            </div>
        </div>
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t-2 border-black pt-4">
        <a href="{{ route('admin-users.index') }}" class="crm-button crm-button--secondary crm-button--md">Batal</a>
        <div class="flex flex-wrap gap-2">
            <x-crm.button type="button" variant="secondary" x-show="step > 1" @click="back">Kembali</x-crm.button>
            <x-crm.button type="button" variant="primary" accent="administration" x-show="step < 3" @click="next">Lanjutkan</x-crm.button>
            <x-crm.button type="submit" variant="primary" accent="administration" x-show="step === 3 && provisioningMode === 'invitation'" name="submit_action" value="draft">Simpan Draf</x-crm.button>
            <x-crm.button type="submit" variant="primary" accent="administration" x-show="step === 3 && provisioningMode === 'invitation'" name="submit_action" value="send">Simpan &amp; Kirim Undangan</x-crm.button>
            @if(Auth::user()->isSuperadmin())<x-crm.button type="submit" variant="primary" accent="administration" x-show="step === 3 && provisioningMode === 'direct'" name="submit_action" value="activate">Aktifkan Akun</x-crm.button>@endif
        </div>
    </div>
</form>
@endsection
