@extends('layouts.crm')

@section('title', 'Tambah Pengeluaran - Oasis CRM')

@section('content')
<x-crm.page-header color="#b3bd95" title="Tambah Pengeluaran" />
@include('crm.expenses._form', ['action' => route('expenses.store'), 'method' => 'POST'])
@endsection
