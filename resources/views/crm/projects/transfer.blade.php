@extends('layouts.crm')

@section('title', 'Pindah Proyek Antar Cabang - Oasis CRM')

@section('content')
    <x-crm.page-header color="#5d8e8e" title="Pindah Proyek Antar Cabang" />

    <div class="border-2 border-black bg-white" x-data="projectTransfer({
        previewUrl: @js(route('projects.transfer.preview')),
        storeUrl: @js(route('projects.transfer.store')),
        branches: @js($branches->map(fn ($branch) => ['id' => $branch->id, 'name' => $branch->name, 'sheet_id' => $branch->sheet_id, 'users' => $branch->users->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()])->values()),
        projects: @js($projects->map(fn ($project) => ['id' => $project->id, 'branch_id' => $project->branch_id, 'name' => $project->project_name, 'user_ids' => $project->assignedUsers->pluck('id')->values()])->values()),
    })" @keydown.escape.window="closeErrors()">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">Wizard Tahap B</div>
        <form class="p-4 sm:p-6 space-y-5" @submit.prevent="submit">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="transfer-source" class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang asal</label>
                    <select id="transfer-source" x-model="sourceId" @change="resetProjects" required class="w-full border-2 border-black px-3 py-2 rounded-none">
                        <option value="">Pilih cabang asal</option>
                        <template x-for="branch in branches" :key="branch.id"><option :value="branch.id" x-text="branch.name"></option></template>
                    </select>
                </div>
                <div>
                    <label for="transfer-target" class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang tujuan</label>
                    <select id="transfer-target" x-model="targetId" required class="w-full border-2 border-black px-3 py-2 rounded-none">
                        <option value="">Pilih cabang tujuan</option>
                        <template x-for="branch in branches" :key="branch.id"><option :value="branch.id" x-text="branch.name"></option></template>
                    </select>
                </div>
            </div>

            <div>
                <p class="font-[Helvetica] font-bold text-xs uppercase mb-2">Proyek</p>
                <div class="border-2 border-black divide-y divide-black max-h-56 overflow-auto">
                    <template x-for="project in sourceProjects" :key="project.id">
                        <label class="flex items-center gap-3 p-3 cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" :value="project.id" x-model="projectIds" class="h-4 w-4">
                            <span x-text="project.name"></span>
                        </label>
                    </template>
                    <p x-show="sourceId && sourceProjects.length === 0" class="p-3 text-sm">Tidak ada proyek aktif pada cabang asal.</p>
                    <p x-show="!sourceId" class="p-3 text-sm">Pilih cabang asal.</p>
                </div>
            </div>

            <div class="border-2 border-black bg-white p-3 space-y-2">
                <h2 class="font-[Helvetica] font-bold uppercase text-sm">Mapping user</h2>
                <p class="text-sm">Semua user aktif cabang asal tampil. Default Tetap. Role dan permission tidak berubah.</p>
                <template x-for="user in mappedUsers" :key="user.id">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-black pt-2">
                        <span x-text="user.name"></span>
                        <select x-model="userMapping[user.id]" class="border-2 border-black px-2 py-1 rounded-none" :aria-label="`Keputusan untuk ${user.name}`">
                            <option value="pindah">Pindah</option>
                            <option value="tetap">Tetap</option>
                            <option value="keduanya">Keduanya</option>
                        </select>
                    </div>
                </template>
                <p x-show="sourceId && mappedUsers.length === 0" class="text-sm">Pilih proyek untuk melihat user.</p>
            </div>

            <div class="border-2 border-black bg-[#fff3b0] p-3 text-sm">
                <p class="font-bold">Pratinjau wajib sebelum eksekusi.</p>
                <p>Cache Sheet tidak dipindah. Data branch-only tetap di cabang asal.</p>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="button" @click="preview" :disabled="busy || !canPreview" class="bg-white text-black px-5 py-2 border-2 border-black font-[Helvetica] font-bold disabled:opacity-50">Pratinjau</button>
                <button type="submit" :disabled="busy || !canSubmit" class="bg-black text-white px-5 py-2 border-2 border-black font-[Helvetica] font-bold disabled:opacity-50">Pindahkan Semua</button>
            </div>

            <div x-show="error" x-cloak role="alert" class="border-2 border-[#e91d2a] bg-red-50 text-[#e91d2a] p-3" x-text="error"></div>
            <div x-show="previewData" x-cloak class="border-2 border-black p-3 space-y-3">
                <h2 class="font-[Helvetica] font-bold uppercase text-sm">Hasil pratinjau</h2>
                <p x-show="previewData?.blockers?.length" class="text-[#e91d2a] font-bold">Blocker: <span x-text="previewData?.blockers?.join(' | ')"></span></p>
                <p x-show="previewData && !previewData.blockers.length" class="text-green-800 font-bold">Tidak ada blocker.</p>
                <p class="text-sm">Warning: <span x-text="previewData?.warnings?.join(' | ')"></span></p>
                <label for="transfer-confirmation" class="font-[Helvetica] font-bold text-xs uppercase block">Ketik nama cabang tujuan untuk konfirmasi</label>
                <input id="transfer-confirmation" type="text" x-model="confirmation" :placeholder="targetName" class="w-full border-2 border-black px-3 py-2 rounded-none">
            </div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
function projectTransfer(config) {
    return {
        ...config, sourceId: '', targetId: '', projectIds: [], userMapping: {}, confirmation: '', previewData: null, busy: false, error: '',
        get sourceProjects() { return this.projects.filter(project => String(project.branch_id) === String(this.sourceId)); },
        get mappedUsers() {
            const userIds = new Set(this.sourceProjects.flatMap(project => project.user_ids || []).map(String));
            return (this.branches.find(branch => String(branch.id) === String(this.sourceId))?.users || []).filter(user => userIds.has(String(user.id)));
        },
        get targetName() { return this.branches.find(branch => String(branch.id) === String(this.targetId))?.name || ''; },
        get canPreview() { return this.sourceId && this.targetId && this.projectIds.length && this.sourceId !== this.targetId; },
        get canSubmit() { return this.previewData && this.previewData.blockers.length === 0 && this.confirmation === this.targetName; },
        resetProjects() { this.projectIds = []; this.userMapping = {}; this.previewData = null; this.confirmation = ''; },
        syncMapping() { this.mappedUsers.forEach(user => { if (!this.userMapping[user.id]) this.userMapping[user.id] = 'tetap'; }); },
        closeErrors() { this.error = ''; },
        async request(url, body) {
            const response = await fetch(url, { method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value}, body: JSON.stringify(body) });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || 'Permintaan gagal.');
            return data;
        },
        async preview() { this.syncMapping(); this.busy = true; this.error = ''; try { this.previewData = await this.request(this.previewUrl, {source_branch_id: this.sourceId, target_branch_id: this.targetId, project_ids: this.projectIds, user_mapping: this.userMapping}); } catch (error) { this.error = error.message; } finally { this.busy = false; } },
        async submit() { this.syncMapping(); this.busy = true; this.error = ''; try { await this.request(this.storeUrl, {source_branch_id: this.sourceId, target_branch_id: this.targetId, project_ids: this.projectIds, user_mapping: this.userMapping, confirmation: this.confirmation}); window.location.href = '{{ route('projects.index') }}'; } catch (error) { this.error = error.message; } finally { this.busy = false; } },
    };
}
</script>
@endpush
