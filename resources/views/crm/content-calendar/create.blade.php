@extends('layouts.crm')
@section('title', 'Tambah Work Planner - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Work Planner" title="Tambah Item" description="Buat task, agenda, atau konten sesuai cabang dan scope kerja Anda.">
    <x-slot:actions>
        <x-crm.button variant="secondary" :href="route('content-calendar.index', ['view' => request('view', 'today')])">Kembali</x-crm.button>
    </x-slot:actions>
</x-crm.page-header>
<x-crm.card variant="default" padding="lg">
    @include('crm.content-calendar._form')
</x-crm.card>
@endsection
