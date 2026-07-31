@extends('layouts.crm')

@section('title', 'Edit Pengeluaran - Oasis CRM')

@section('content')
<x-crm.page-header
    variant="canonical"
    title="Edit Pengeluaran"
    eyebrow="Keuangan"
    description="Perbarui data pengeluaran aktif. Perubahan memakai pemeriksaan konflik agar riwayat tidak tertimpa."
>
    <x-slot:actions>
        <x-crm.button href="{{ route('expenses.show', $expense) }}" variant="secondary">Kembali</x-crm.button>
    </x-slot:actions>
</x-crm.page-header>
@include('crm.expenses._form', ['action' => route('expenses.update', $expense), 'method' => 'PUT'])
@endsection
