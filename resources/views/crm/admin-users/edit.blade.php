@extends('layouts.crm')
@section('title', 'Edit Pengguna - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Edit Pengguna: {{ $user->name }}" />
<div class="border-2 border-black bg-white"><div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Identitas dan Penugasan</div>
<form method="POST" action="{{ route('admin-users.update', $user) }}" class="p-4 space-y-4">@csrf @method('PUT')<input type="hidden" name="expected_updated_at" value="{{ $lockToken }}">
    @include('crm.admin-users._form', ['user' => $user])
    @if($user->hasPrimaryRole('sales_coordinator'))
    @php($selectedSalesIds = collect(old('coordinator_sales_ids', $user->currentCoordinatorSales->pluck('id')->all()))->map(fn ($id) => (int) $id))
    <fieldset class="border-t-2 border-black pt-3"><legend class="mb-2 block text-xs font-bold uppercase">Tim Sales Aktif</legend><div class="grid gap-2 sm:grid-cols-2">@forelse($coordinatorSales as $sales)<label class="flex min-h-11 items-center gap-2 border border-black px-3 py-2 text-sm"><input type="checkbox" name="coordinator_sales_ids[]" value="{{ $sales->id }}" @checked($selectedSalesIds->contains($sales->id))><span>{{ $sales->name }}</span></label>@empty<p class="text-sm">Tidak ada Sales aktif dalam akses Anda.</p>@endforelse</div>@error('coordinator_sales_ids')<p class="mt-1 text-xs font-bold text-[#e91d2a]">{{ $message }}</p>@enderror @error('coordinator_sales_ids.*')<p class="mt-1 text-xs font-bold text-[#e91d2a]">{{ $message }}</p>@enderror</fieldset>
    @endif
    @if($user->isSales())<div class="border-t-2 border-black pt-3"><strong class="block text-xs uppercase mb-2">Identitas Sales PIC Spreadsheet</strong><div class="flex flex-wrap gap-2">@foreach($user->branches as $branch)<a class="crm-button crm-button--secondary crm-button--sm" href="{{ route('admin-users.sales-sheet-identity.edit', [$user, $branch]) }}">{{ $branch->name }}</a>@endforeach</div></div>@endif
    <p class="text-xs font-['Times_New_Roman']">Status akun dan akses masuk dikelola melalui tindakan terpisah. Kata sandi tidak dapat dilihat atau diubah admin.</p>
    <div class="flex gap-2"><button class="bg-black text-white border-2 border-black px-5 py-2 text-xs font-bold">SIMPAN</button><a href="{{ route('admin-users.show', $user) }}" class="bg-white border-2 border-black px-5 py-2 text-xs font-bold">BATAL</a></div>
</form></div>
@endsection
