@extends('layouts.crm')

@section('title', 'Edit Lead - Buku Saku Sales')

@section('content')
<x-crm.page-header color="#fcc20f" title="Edit Lead Buku Saku" />
<x-crm.page-presence page-key="sales-pocketbook" :branch-id="$lead->branch_id" record-type="sales_lead" :record-id="$lead->id" mode="editing" />

<div class="border-2 border-black bg-white">
    <div class="bg-black px-4 py-2 font-[Helvetica] text-xs font-bold uppercase text-[#fcc20f]">Data Lead</div>
    <form method="POST" action="{{ route('sales-leads.update', $lead) }}" x-data="salesCascade(@js($projects->map(fn ($project) => ['id' => (string) $project->id, 'branch_id' => (string) $project->branch_id, 'sales_ids' => $project->assignedUsers->pluck('id')->map(fn ($id) => (string) $id)->all()])), @js($salesUsers->map(fn ($sales) => ['id' => (string) $sales->id, 'project_ids' => $sales->assignedProjects->pluck('id')->map(fn ($id) => (string) $id)->all()])), @js(['branch' => old('branch_id', $lead->branch_id), 'project' => old('project_id', $lead->project_id), 'sales' => old('sales_user_id', $lead->sales_user_id)]))" class="grid grid-cols-1 gap-4 p-4 md:grid-cols-2" data-conflict-form>
        @csrf
        @method('PUT')
        <input type="hidden" name="expected_updated_at" value="{{ old('expected_updated_at', $optimisticToken) }}">

        <div><label class="sales-label">Tanggal Lead</label><x-crm.date-field name="lead_date" :value="old('lead_date', $lead->lead_date->toDateString())" required />@error('lead_date')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Nama Calon Konsumen</label><input class="sales-input" name="customer_name" value="{{ old('customer_name', $lead->customer_name) }}" required>@error('customer_name')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">No. WhatsApp / Telepon</label><input class="sales-input" name="phone" value="{{ old('phone', $lead->phone) }}">@error('phone')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Sumber Lead</label><select class="sales-input" name="lead_source_id" required>@foreach($leadSources as $source)<option value="{{ $source->id }}" @selected(old('lead_source_id', $lead->lead_source_id) == $source->id)>{{ $source->name }}{{ $source->is_active ? '' : ' (nonaktif)' }}</option>@endforeach</select>@error('lead_source_id')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Cabang</label><select class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Proyek</label><select class="sales-input" name="project_id" x-model="project" @change="projectChanged()" required>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select>@error('project_id')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Sales</label><select class="sales-input" name="sales_user_id" x-model="sales" required @disabled(Auth::user()->isSales())>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select>@if(Auth::user()->isSales())<input type="hidden" name="sales_user_id" value="{{ $lead->sales_user_id }}">@endif @error('sales_user_id')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Referensi Konsumen Tertaut</label><input class="sales-input" name="linked_consumer_reference" value="{{ old('linked_consumer_reference', $lead->linked_consumer_reference) }}">@error('linked_consumer_reference')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div class="md:col-span-2"><label class="sales-label">Catatan</label><textarea class="sales-input" name="notes" rows="4">{{ old('notes', $lead->notes) }}</textarea>@error('notes')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div class="flex gap-2 md:col-span-2"><button class="sales-button bg-[#fcc20f]">Simpan Perubahan</button><a href="{{ route('sales-pocketbook.index') }}" class="sales-button bg-white">Batal</a></div>
    </form>
</div>

@if(auth()->user()->hasPermission('comments.view'))
<a href="{{ route('comments.thread', ['alias' => 'sales-lead', 'id' => $lead->id]) }}" class="mt-4 inline-block border-2 border-black bg-white px-4 py-2 font-[Helvetica] text-xs font-bold uppercase">Komentar ({{ $lead->comments_count }})</a>
@endif

<style>
    .sales-label{display:block;margin-bottom:4px;font:700 11px Helvetica;text-transform:uppercase}.sales-input{width:100%;border:2px solid #000;border-radius:0;background:#fff;padding:8px 10px;font:14px 'Times New Roman'}.sales-button{display:inline-block;border:2px solid #000;padding:8px 14px;font:700 11px Helvetica;text-transform:uppercase;box-shadow:2px 2px 0 #000}.sales-error{margin-top:4px;color:#c0392b;font:700 11px Helvetica}
</style>
<script>
function salesCascade(projects, salesUsers, initial = {}) {
    return { projects, salesUsers, branch: String(initial.branch || ''), project: String(initial.project || ''), sales: String(initial.sales || ''), projectVisible(id) { return this.projects.find(item => item.id === String(id))?.branch_id === this.branch }, salesVisible(id) { const user = this.salesUsers.find(item => item.id === String(id)); return Boolean(user && (!this.project || user.project_ids.includes(this.project))) }, branchChanged() { if (!this.projectVisible(this.project)) this.project = ''; if (!this.salesVisible(this.sales)) this.sales = '' }, projectChanged() { const selected = this.projects.find(item => item.id === this.project); if (selected) this.branch = selected.branch_id; if (!this.salesVisible(this.sales)) this.sales = '' } }
}
</script>
@endsection
