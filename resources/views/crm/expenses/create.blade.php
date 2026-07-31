@extends('layouts.crm')

@section('title', 'Tambah Pengeluaran - Oasis CRM')

@section('content')
<x-crm.page-header
    variant="canonical"
    title="Tambah Pengeluaran"
    eyebrow="Keuangan"
    description="Catat pengeluaran manual dengan cabang, proyek, kategori, dan nominal yang presisi."
>
    <x-slot:actions>
        <x-crm.button href="{{ route('expenses.index') }}" variant="secondary">Kembali</x-crm.button>
    </x-slot:actions>
</x-crm.page-header>
@include('crm.expenses._form', ['action' => route('expenses.store'), 'method' => 'POST'])
@endsection
