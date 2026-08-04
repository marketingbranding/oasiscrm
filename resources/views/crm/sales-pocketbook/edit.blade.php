@extends('layouts.crm')

@section('title', 'Edit Lead - Buku Saku Sales')

@section('content')
@php($leadOptionsEndpoint = route('sales-leads.options', ['branch' => 'BRANCH_ID']))
<x-crm.page-header
    variant="canonical"
    class="sales-pocketbook-page-header"
    eyebrow="Buku Saku Sales"
    title="Edit Lead"
    description="Perbarui identitas, penugasan, dan catatan lead tanpa mengubah progres yang sudah tercatat."
>
    <x-slot:actions><x-crm.button variant="secondary" :href="route('sales-pocketbook.index')">Kembali</x-crm.button></x-slot:actions>
</x-crm.page-header>
<x-crm.page-presence page-key="sales-pocketbook" :branch-id="$lead->branch_id" record-type="sales_lead" :record-id="$lead->id" mode="editing" />

@if($errors->any())
    <x-crm.alert variant="error" title="Perubahan belum tersimpan" role="alert">Periksa kembali bidang yang ditandai. {{ $errors->first() }}</x-crm.alert>
@endif

<x-crm.section id="sales-lead-edit" title="Data Lead" description="Bidang bertanda bintang wajib diisi." class="sales-pocketbook-edit-section">
    <x-crm.card padding="none">
    <form method="POST" action="{{ route('sales-leads.update', $lead) }}" x-data="salesCascade(@js($projects->map(fn ($project) => ['id' => (string) $project->id, 'branch_id' => (string) $project->branch_id, 'sales_ids' => $project->assignedUsers->pluck('id')->map(fn ($id) => (string) $id)->all()])), @js($salesUsers->map(fn ($sales) => ['id' => (string) $sales->id, 'project_ids' => $sales->assignedProjects->pluck('id')->map(fn ($id) => (string) $id)->all()])), @js(['branch' => old('branch_id', $lead->branch_id), 'project' => old('project_id', $lead->project_id), 'sales' => old('sales_user_id', $lead->sales_user_id), 'optionsEndpoint' => $leadOptionsEndpoint, 'source' => old('source', $lead->source), 'platform' => old('platform', $lead->platform), 'campaignName' => old('campaign_name', $lead->campaign_name), 'promo' => old('id_promo', $lead->id_promo)]))" @submit="setSubmitting()" class="sales-pocketbook-edit-form" data-conflict-form :aria-busy="submitting">
        @csrf
        @method('PUT')
        <input type="hidden" name="expected_updated_at" value="{{ old('expected_updated_at', $optimisticToken) }}">

        <x-crm.field label="Tanggal Lead" for="edit-lead-date" required :error="$errors->first('lead_date')"><x-crm.date-field id="edit-lead-date" name="lead_date" :value="old('lead_date', $lead->lead_date->toDateString())" required :aria-invalid="$errors->has('lead_date') ? 'true' : 'false'" :aria-describedby="$errors->has('lead_date') ? 'edit-lead-date-error' : null" /></x-crm.field>
        <x-crm.field label="Nama Calon Konsumen" for="edit-lead-customer-name" required :error="$errors->first('customer_name')"><input id="edit-lead-customer-name" class="sales-input" name="customer_name" value="{{ old('customer_name', $lead->customer_name) }}" required aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" @if($errors->has('customer_name')) aria-describedby="edit-lead-customer-name-error" @endif></x-crm.field>
        <x-crm.field label="No. WhatsApp / Telepon" for="edit-lead-phone" hint="Pemeriksaan duplikat hanya berupa peringatan dan tidak mencegah penyimpanan." :error="$errors->first('phone')" data-duplicate-url="{{ route('sales-leads.duplicate-phone') }}" data-lead-id="{{ $lead->id }}" x-data="salesDuplicatePhone($el.dataset.duplicateUrl, Number($el.dataset.leadId))">
            <input id="edit-lead-phone" class="sales-input" name="phone" value="{{ old('phone', $lead->phone) }}" @blur="checkPhone($event.target.value)" x-bind:aria-busy="duplicatePending" aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}" aria-describedby="edit-lead-phone-hint{{ $errors->has('phone') ? ' edit-lead-phone-error' : '' }} edit-lead-duplicate-status">
            <div id="edit-lead-duplicate-status" class="sales-pocketbook-duplicate-status" aria-live="polite" aria-atomic="true"><p x-show="duplicatePending" x-cloak>Memeriksa nomor duplikat...</p><div x-show="!duplicatePending && duplicates.length" x-cloak class="sales-pocketbook-duplicate-result"><strong>Peringatan duplikat, lead tetap dapat disimpan.</strong><template x-for="item in duplicates" :key="item.id"><div x-text="`${item.sales} / ${item.branch} / ${item.project} / ${item.date}`"></div></template></div></div>
        </x-crm.field>
        <x-crm.field label="Sumber Lead" for="edit-lead-source" required :error="$errors->first('source')"><select id="edit-lead-source" class="sales-input" name="source" x-model="source" required><option value="">Pilih sumber</option><template x-for="option in sheetOptions.source" :key="option"><option :value="option" x-text="option"></option></template></select><p x-show="historicalSource" x-cloak class="mt-1 text-xs text-amber-800">Nilai sebelumnya “<span x-text="historicalSource"></span>” tidak tersedia lagi. Pilih sumber aktif sebelum menyimpan.</p></x-crm.field>
        <x-crm.field label="Kanal Masuk" for="edit-lead-platform" required :error="$errors->first('platform')"><select id="edit-lead-platform" class="sales-input" name="platform" x-model="platform" required><template x-if="platform && !sheetOptions.channel.includes(platform)"><option :value="platform" x-text="platform"></option></template><template x-for="option in sheetOptions.channel" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
        <x-crm.field label="Aktivitas Lead" for="edit-lead-campaign" required :error="$errors->first('campaign_name')"><select id="edit-lead-campaign" class="sales-input" name="campaign_name" x-model="campaignName" required><template x-if="campaignName && !sheetOptions.activity.includes(campaignName)"><option :value="campaignName" x-text="campaignName"></option></template><template x-for="option in sheetOptions.activity" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
        <x-crm.field label="ID Promo" for="edit-lead-promo" :error="$errors->first('id_promo')"><select id="edit-lead-promo" class="sales-input" name="id_promo" x-model="promo"><option value="">Pilih ID Promo (opsional)</option><template x-if="promo && !sheetOptions.promo.includes(promo)"><option :value="promo" x-text="promo"></option></template><template x-for="option in sheetOptions.promo" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
        @if($lead->current_status->isManual())
            <x-crm.field label="Status Lead" for="edit-lead-status" :error="$errors->first('current_status')">
                <select id="edit-lead-status" class="sales-input" name="current_status"><option value="no_response" @selected(old('current_status', $lead->current_status->value) === 'no_response')>No Respon</option><option value="discussion" @selected(old('current_status', $lead->current_status->value) === 'discussion')>Diskusi</option><option value="site_visit" @selected(old('current_status', $lead->current_status->value) === 'site_visit')>Cek Lokasi</option></select>
            </x-crm.field>
        @else
            <div class="crm-field">
                <span id="edit-lead-status-label" class="crm-field-label">Status Lead</span>
                <div class="crm-field-control">
                <span class="block border border-gray-400 bg-gray-100 px-3 py-2 text-sm font-bold" aria-labelledby="edit-lead-status-label">{{ $lead->current_status->label() }} (status sistem, baca-saja)</span>
                </div>
            </div>
        @endif
        <x-crm.field label="Cabang" for="edit-lead-branch" required :error="$errors->first('branch_id')"><select id="edit-lead-branch" class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()" required aria-invalid="{{ $errors->has('branch_id') ? 'true' : 'false' }}" @if($errors->has('branch_id')) aria-describedby="edit-lead-branch-error" @endif>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-crm.field>
        <x-crm.field label="Proyek" for="edit-lead-project" required :error="$errors->first('project_id')"><select id="edit-lead-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()" required aria-invalid="{{ $errors->has('project_id') ? 'true' : 'false' }}" @if($errors->has('project_id')) aria-describedby="edit-lead-project-error" @endif>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
        <x-crm.field label="Sales" for="edit-lead-sales" required :error="$errors->first('sales_user_id')"><select id="edit-lead-sales" class="sales-input" name="sales_user_id" x-model="sales" required @disabled(Auth::user()->isSales()) aria-invalid="{{ $errors->has('sales_user_id') ? 'true' : 'false' }}" @if($errors->has('sales_user_id')) aria-describedby="edit-lead-sales-error" @endif>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select>@if(Auth::user()->isSales())<input type="hidden" name="sales_user_id" value="{{ $lead->sales_user_id }}">@endif</x-crm.field>
        <x-crm.field label="Referensi Konsumen Tertaut" for="edit-lead-consumer-reference" :error="$errors->first('linked_consumer_reference')"><input id="edit-lead-consumer-reference" class="sales-input" name="linked_consumer_reference" value="{{ old('linked_consumer_reference', $lead->linked_consumer_reference) }}" aria-invalid="{{ $errors->has('linked_consumer_reference') ? 'true' : 'false' }}" @if($errors->has('linked_consumer_reference')) aria-describedby="edit-lead-consumer-reference-error" @endif></x-crm.field>
        <x-crm.field label="Catatan" for="edit-lead-notes" :error="$errors->first('notes')" class="md:col-span-2"><textarea id="edit-lead-notes" class="sales-input" name="notes" rows="4" aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}" @if($errors->has('notes')) aria-describedby="edit-lead-notes-error" @endif>{{ old('notes', $lead->notes) }}</textarea></x-crm.field>
        <div class="md:col-span-2 text-xs font-bold">Status sinkronisasi: {{ $lead->external_sync_id ? 'Tersinkron dengan UUID '.$lead->external_sync_id : (filled($lead->branch?->sheet_id) ? 'Catatan historis lokal, belum memiliki UUID sinkronisasi.' : 'Spreadsheet cabang belum dikonfigurasi.') }}</div>
        <div class="sales-pocketbook-form-actions md:col-span-2"><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="submitting" x-bind:aria-busy="submitting"><span x-show="!submitting">Simpan Perubahan</span><span x-show="submitting" x-cloak>Menyimpan...</span></x-crm.button><x-crm.button variant="secondary" :href="route('sales-pocketbook.index')">Batal</x-crm.button><span class="sr-only" aria-live="polite" x-text="submitting ? 'Perubahan lead sedang disimpan.' : ''"></span></div>
    </form>
    </x-crm.card>
</x-crm.section>

@if(auth()->user()->hasPermission('comments.view'))
<div class="sales-pocketbook-edit-comments"><x-crm.button variant="secondary" :href="route('comments.thread', ['alias' => 'sales-lead', 'id' => $lead->id])">Komentar ({{ $lead->comments_count }})</x-crm.button></div>
@endif
@endsection
