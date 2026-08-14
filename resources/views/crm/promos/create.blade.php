@extends('layouts.crm')
@section('title', 'Tambah Promo - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Master Promo Cabang" title="Tambah Promo" description="Promo baru wajib memiliki cabang dan kode unik dalam cabang." />
<form method="POST" action="{{ route('promos.store') }}" class="grid max-w-3xl gap-4 border-2 border-black bg-white p-4">@csrf @include('crm.promos._form')</form>
@endsection
