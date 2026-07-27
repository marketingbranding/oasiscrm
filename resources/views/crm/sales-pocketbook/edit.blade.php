@extends('layouts.crm')

@section('title', 'Edit Lead - Buku Saku Sales')

@section('content')
<x-crm.page-header color="#fcc20f" title="Edit Lead Buku Saku" />
<x-crm.page-presence page-key="sales-pocketbook" :branch-id="$lead->branch_id" record-type="sales_lead" :record-id="$lead->id" mode="editing" />

<div class="border-2 border-black bg-white">
    <div class="bg-black px-4 py-2 font-[Helvetica] text-xs font-bold uppercase text-[#fcc20f]">Data Lead</div>
    <form method="POST" action="{{ route('sales-leads.update', $lead) }}" class="grid grid-cols-1 gap-4 p-4 md:grid-cols-2" data-conflict-form>
        @csrf
        @method('PUT')
        <input type="hidden" name="expected_updated_at" value="{{ old('expected_updated_at', $optimisticToken) }}">

        <div><label class="sales-label">Tanggal Lead</label><x-crm.date-field name="lead_date" :value="old('lead_date', $lead->lead_date->toDateString())" required />@error('lead_date')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Nama Calon Konsumen</label><input class="sales-input" name="customer_name" value="{{ old('customer_name', $lead->customer_name) }}" required>@error('customer_name')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">No. WhatsApp / Telepon</label><input class="sales-input" name="phone" value="{{ old('phone', $lead->phone) }}">@error('phone')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Sumber Lead</label><select class="sales-input" name="lead_source_id" required>@foreach($leadSources as $source)<option value="{{ $source->id }}" @selected(old('lead_source_id', $lead->lead_source_id) == $source->id)>{{ $source->name }}</option>@endforeach</select>@error('lead_source_id')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Proyek</label><select class="sales-input" name="project_id" required onchange="document.querySelector('[name=branch_id]').value=this.options[this.selectedIndex].dataset.branch">@foreach($projects as $project)<option value="{{ $project->id }}" data-branch="{{ $project->branch_id }}" @selected(old('project_id', $lead->project_id) == $project->id)>{{ $project->project_name }}</option>@endforeach</select>@error('project_id')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Cabang</label><select class="sales-input" name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $lead->branch_id) == $branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Sales</label><select class="sales-input" name="sales_user_id" required @disabled(Auth::user()->hasRole('sales'))>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" @selected(old('sales_user_id', $lead->sales_user_id) == $sales->id)>{{ $sales->name }}</option>@endforeach</select>@if(Auth::user()->hasRole('sales'))<input type="hidden" name="sales_user_id" value="{{ $lead->sales_user_id }}">@endif @error('sales_user_id')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div><label class="sales-label">Referensi Konsumen Tertaut</label><input class="sales-input" name="linked_consumer_reference" value="{{ old('linked_consumer_reference', $lead->linked_consumer_reference) }}">@error('linked_consumer_reference')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div class="md:col-span-2"><label class="sales-label">Catatan</label><textarea class="sales-input" name="notes" rows="4">{{ old('notes', $lead->notes) }}</textarea>@error('notes')<p class="sales-error">{{ $message }}</p>@enderror</div>
        <div class="flex gap-2 md:col-span-2"><button class="sales-button bg-[#fcc20f]">Simpan Perubahan</button><a href="{{ route('sales-pocketbook.index') }}" class="sales-button bg-white">Batal</a></div>
    </form>
</div>

<style>
    .sales-label{display:block;margin-bottom:4px;font:700 11px Helvetica;text-transform:uppercase}.sales-input{width:100%;border:2px solid #000;border-radius:0;background:#fff;padding:8px 10px;font:14px 'Times New Roman'}.sales-button{display:inline-block;border:2px solid #000;padding:8px 14px;font:700 11px Helvetica;text-transform:uppercase;box-shadow:2px 2px 0 #000}.sales-error{margin-top:4px;color:#c0392b;font:700 11px Helvetica}
</style>
@endsection
