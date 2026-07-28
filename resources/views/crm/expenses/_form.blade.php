@php
    $editing = isset($expense) && $expense;
    $field = 'w-full rounded-none border-2 border-black bg-white px-3 py-2 text-sm font-[Times_New_Roman]';
    $selectedProject = (string) old('project_id', $expense?->project_id);
@endphp
<form method="POST" action="{{ $action }}" x-data="expenseForm(@js([
    'branchId' => (string) old('branch_id', $initialBranchId),
    'projectId' => $selectedProject,
    'projects' => $projects->map(fn ($project) => ['id' => (string) $project->id, 'name' => $project->project_name])->values(),
    'projectsUrl' => route('expenses.projects'),
]))" @submit="if (submitting) { $event.preventDefault() } else { submitting = true }" class="border-2 border-black bg-white">
    @csrf
    @if($method !== 'POST') @method($method) @endif
    @if($editing)<input type="hidden" name="expected_updated_at" value="{{ old('expected_updated_at', $optimisticToken) }}">@endif
    @unless($editing)<input x-ref="submitAction" type="hidden" name="submit_action" value="save">@endunless

    <div class="bg-black px-4 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">Data Pengeluaran</div>
    <div class="grid grid-cols-1 gap-4 p-4 md:grid-cols-2">
        <div>
            <label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Tanggal Pengeluaran</label>
            <x-crm.date-field name="expense_date" :value="old('expense_date', $expense?->expense_date?->toDateString() ?? now()->toDateString())" required accent="#b3bd95" />
            @error('expense_date')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Cabang</label>
            <select name="branch_id" x-model="branchId" @change="loadProjects(true)" required class="{{ $field }}">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
            </select>
            @error('branch_id')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Proyek</label>
            <select name="project_id" x-model="projectId" :disabled="loadingProjects || !branchId" class="{{ $field }}">
                <option value="" x-text="loadingProjects ? 'Memuat proyek...' : '— Tanpa Proyek —'"></option>
                <template x-for="project in projects" :key="project.id"><option :value="project.id" x-text="project.name"></option></template>
            </select>
            <p x-show="!loadingProjects && branchId && !projectError && projects.length === 0" class="mt-1 text-xs italic">Tidak ada proyek aktif untuk cabang ini.</p>
            <p x-show="projectError" x-cloak class="mt-1 text-xs font-bold text-[#c0392b]"><span x-text="projectError"></span> <button type="button" @click="loadProjects(false)" class="underline">Coba lagi</button></p>
            @error('project_id')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Kategori</label>
            @if($categories->isEmpty())
                <div class="border-2 border-black bg-[#fff3b0] px-3 py-2 text-sm">Belum ada kategori pengeluaran aktif.</div>
            @else
                <select name="expense_category_id" required class="{{ $field }}">
                    <option value="">— Pilih Kategori —</option>
                    @foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('expense_category_id', $expense?->expense_category_id) === (string) $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (Tidak Aktif)' }}</option>@endforeach
                </select>
            @endif
            @error('expense_category_id')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Jumlah (Rp)</label>
            <input type="number" name="amount" value="{{ old('amount', $expense?->amount) }}" min="0.01" max="9999999999999.99" step="0.01" inputmode="decimal" required class="{{ $field }}">
            @error('amount')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Metode Pembayaran</label>
            <select name="payment_method" class="{{ $field }}"><option value="">— Pilih Metode —</option>@foreach($paymentMethods as $value => $label)<option value="{{ $value }}" @selected(old('payment_method', $expense?->payment_method) === $value)>{{ $label }}</option>@endforeach</select>
            @error('payment_method')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Deskripsi</label>
            <input type="text" name="description" maxlength="255" value="{{ old('description', $expense?->description) }}" required class="{{ $field }}">
            @error('description')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror
        </div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Nama Vendor</label><input type="text" name="vendor_name" maxlength="255" value="{{ old('vendor_name', $expense?->vendor_name) }}" class="{{ $field }}">@error('vendor_name')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror</div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Nomor Referensi</label><input type="text" name="reference_number" maxlength="255" value="{{ old('reference_number', $expense?->reference_number) }}" class="{{ $field }}">@error('reference_number')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror</div>
        <div class="md:col-span-2"><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Catatan</label><textarea name="notes" maxlength="2000" rows="4" class="{{ $field }}">{{ old('notes', $expense?->notes) }}</textarea>@error('notes')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror</div>
    </div>
    <div class="flex flex-wrap gap-2 border-t-2 border-black bg-gray-100 p-4">
        <button type="submit" @unless($editing) @click="$refs.submitAction.value = 'save'" @endunless :disabled="submitting || {{ $categories->isEmpty() ? 'true' : 'false' }}" class="border-2 border-black bg-[#b3bd95] px-4 py-2 font-bold disabled:opacity-50">{{ $editing ? 'Simpan Perubahan' : 'Simpan' }}</button>
        @unless($editing)<button type="submit" @click="$refs.submitAction.value = 'add_another'" :disabled="submitting || {{ $categories->isEmpty() ? 'true' : 'false' }}" class="border-2 border-black bg-white px-4 py-2 font-bold disabled:opacity-50">Simpan &amp; Tambah Lagi</button>@endunless
        <a href="{{ $editing ? route('expenses.show', $expense) : route('expenses.index') }}" class="border-2 border-black bg-white px-4 py-2 font-bold">Batal</a>
    </div>
</form>

@once
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('expenseForm', config => ({
        branchId: config.branchId,
        projectId: config.projectId,
        projects: config.projects,
        projectsUrl: config.projectsUrl,
        loadingProjects: false,
        projectError: '',
        submitting: false,
        async loadProjects(clearSelection) {
            if (clearSelection) this.projectId = '';
            this.projects = [];
            this.projectError = '';
            if (!this.branchId) return;
            this.loadingProjects = true;
            try {
                const response = await fetch(`${this.projectsUrl}?branch_id=${encodeURIComponent(this.branchId)}`, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'Pilihan proyek gagal dimuat. Silakan coba lagi.');
                this.projects = data.projects;
            } catch (error) {
                this.projectError = error.message || 'Pilihan proyek gagal dimuat. Silakan coba lagi.';
            } finally {
                this.loadingProjects = false;
            }
        },
    }));
});
</script>
@endonce
