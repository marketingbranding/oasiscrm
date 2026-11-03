@extends('layouts.crm')

@section('title', 'Buku Saku Saya - Oasis CRM')

@section('body-class', 'sales-personal-page')

@section('content')
<div class="sales-pocketbook-workspace sales-pocketbook-screen-section sales-reference-shell sales-receipt-page space-y-4" data-composition="sales-pocketbook-composer" x-data="salesAgendaWorkspace()">
    @if($dailyReminder['shouldShow']) @include('crm.sales-pocketbook._daily-reminder', ['dailyReminder' => $dailyReminder]) @endif
    @include('crm.sales-pocketbook.partials.buku-saku-header')

    @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>@endif

    @if($tab === 'leads')
        @include('crm.sales-pocketbook.partials.lead-saya')
    @else
        @include('crm.sales-pocketbook.partials.agenda-receipt')
    @endif

    @if($showSalesTour)
        @include('crm.sales-pocketbook.partials.sales-tour', ['steps' => \App\Services\SalesPocketbookOnboardingService::STEPS])
    @endif
</div>
@endsection
