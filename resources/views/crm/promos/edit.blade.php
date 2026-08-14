@extends('layouts.crm')
@section('title', 'Edit Promo - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Master Promo Cabang" title="Edit Promo" description="Perbarui identitas dan periode promo tanpa menghapus riwayat." />
<form method="POST" action="{{ route('promos.update', $promo) }}" class="grid max-w-3xl gap-4 border-2 border-black bg-white p-4">@csrf @method('PUT') @include('crm.promos._form')</form>
@endsection
