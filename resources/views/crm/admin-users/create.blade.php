@extends('layouts.crm')
@section('title', 'Buat Pengguna - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Buat Pengguna" />
<div class="border-2 border-black bg-white"><div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Data Akun dan Organisasi</div>
<form method="POST" action="{{ route('admin-users.store') }}" class="p-4 space-y-4">@csrf
    @include('crm.admin-users._form')

    <fieldset class="border-2 border-black">
        <legend class="ml-3 bg-black px-2 py-1 font-[Helvetica] text-xs font-bold uppercase text-white">Password Awal</legend>
        <div class="space-y-3 p-4">
            <p class="text-sm">Akun langsung aktif tanpa email aktivasi. Pengguna akan diminta mengganti password ini saat login pertama.</p>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label for="temporary_password" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Password Awal</label><x-password-input id="temporary_password" name="temporary_password" required autocomplete="new-password" class="w-full rounded-none border-2 border-black px-3 py-2 text-sm" />@error('temporary_password')<p class="mt-1 text-xs font-bold text-[#e91d2a]">{{ $message }}</p>@enderror</div>
                <div><label for="temporary_password_confirmation" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Konfirmasi Password Awal</label><x-password-input id="temporary_password_confirmation" name="temporary_password_confirmation" required autocomplete="new-password" class="w-full rounded-none border-2 border-black px-3 py-2 text-sm" /></div>
            </div>
        </div>
    </fieldset>

    <div class="flex flex-wrap gap-2"><button type="submit" class="bg-[#8c9ae0] border-2 border-black px-4 py-2 text-xs font-bold">SIMPAN &amp; AKTIFKAN</button><a href="{{ route('admin-users.index') }}" class="bg-white border-2 border-black px-4 py-2 text-xs font-bold">BATAL</a></div>
</form></div>
@endsection
