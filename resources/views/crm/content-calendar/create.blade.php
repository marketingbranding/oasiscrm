@extends('layouts.crm')
@section('title', 'Tambah Work Planner - Oasis CRM')
@section('content')
<x-crm.page-header color="#b3bd95" title="Tambah Work Planner" />
<div class="border-2 border-black bg-white p-4 sm:p-6">
    @include('crm.content-calendar._form')
</div>
@endsection
