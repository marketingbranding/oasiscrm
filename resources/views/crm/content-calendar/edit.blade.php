@extends('layouts.crm')
@section('title', 'Edit Work Planner - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Work Planner" title="Edit Item" description="Perbarui item dengan perlindungan konflik kolaborasi dan validasi server.">
    <x-slot:actions>
        <x-crm.button variant="secondary" :href="route('content-calendar.index', ['view' => request('view', 'today')])">Kembali</x-crm.button>
    </x-slot:actions>
</x-crm.page-header>
<x-crm.page-presence page-key="work-planner" :branch-id="$item->branch_id" record-type="content_item" :record-id="$item->id" mode="editing" />
<x-crm.card variant="default" padding="lg">
    @include('crm.content-calendar._form')
</x-crm.card>
@endsection
