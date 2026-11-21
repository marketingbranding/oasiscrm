@extends('layouts.crm')

@section('title', 'Buku Saku Saya - Oasis CRM')

@section('body-class', 'sales-personal-page')

@section('content')
<div class="sales-pocketbook-workspace sales-reference-shell sales-receipt-page space-y-4" data-composition="sales-pocketbook-screen-section sales-pocketbook-composer" data-resume-receipt="{{ $resumeReceipt ? 'true' : 'false' }}" x-data="salesAgendaWorkspace()">
    @if($dailyReminder['shouldShow']) @include('crm.sales-pocketbook._daily-reminder', ['dailyReminder' => $dailyReminder]) @endif
    @include('crm.sales-pocketbook.partials.buku-saku-header')

    @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>@endif

    @if($tab === 'leads')
        @include('crm.sales-pocketbook.partials.lead-saya')
    @else
        @if($followUpLeads->isNotEmpty())
            <section class="sales-pocketbook-screen-section" aria-labelledby="follow-up-leads-title">
                <header class="sales-lead-heading">
                    <span>Pengingat Lead</span>
                    <h2 id="follow-up-leads-title">Perlu dihubungi</h2>
                    <p>Lead dengan hasil Cek Lokasi Follow-up.</p>
                </header>
                <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Lead</th><th>Proyek</th><th>Hasil terakhir</th><th>Aksi</th></tr></thead><tbody>
                    @foreach($followUpLeads as $followUpLead)
                        <tr><td>{{ $followUpLead->customer_name }}</td><td>{{ $followUpLead->project?->project_name ?: '-' }}</td><td>{{ $followUpLead->latestFollowUpSiteVisit?->visit_date?->format('d/m/Y') ?: '-' }}</td><td><x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $followUpLead)">Lihat lead</x-crm.button></td></tr>
                    @endforeach
                </tbody></table></div>
            </section>
        @endif
        @include('crm.sales-pocketbook.partials.agenda-receipt')
    @endif

    @if($showSalesTour)
        @include('crm.sales-pocketbook.partials.sales-tour', ['steps' => \App\Services\SalesPocketbookOnboardingService::STEPS])
    @endif
</div>
@endsection
