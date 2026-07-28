@extends('layouts.crm')

@section('title', 'Edit Pengeluaran - Oasis CRM')

@section('content')
<x-crm.page-header color="#b3bd95" title="Edit Pengeluaran" />
@include('crm.expenses._form', ['action' => route('expenses.update', $expense), 'method' => 'PUT'])
@endsection
