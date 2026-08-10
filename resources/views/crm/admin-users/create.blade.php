@extends('layouts.crm')
@section('title', 'Buat Pengguna - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Buat Pengguna" />
<div class="border-2 border-black bg-white"><div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Data Akun dan Organisasi</div>
<form method="POST" action="{{ route('admin-users.store') }}" class="p-4 space-y-4" x-data="{ provisioningMode: @js(old('provisioning_mode') === 'direct' && Auth::user()->isSuperadmin() ? 'direct' : 'invitation') }">@csrf
    @include('crm.admin-users._form')

    <fieldset class="border-2 border-black">
        <legend class="ml-3 bg-black px-2 py-1 font-[Helvetica] text-xs font-bold uppercase text-white">Aktivasi Akun</legend>
        <div class="space-y-3 p-4">
            <label class="flex items-start gap-2 text-sm font-[Helvetica] font-bold">
                <input type="radio" name="provisioning_mode" value="invitation" x-model="provisioningMode">
                <span>Kirim Undangan<span class="mt-1 block font-['Times_New_Roman'] text-xs font-normal">User melakukan aktivasi melalui email dan membuat password sendiri.</span></span>
            </label>
            @if(Auth::user()->isSuperadmin())
            <label class="flex items-start gap-2 text-sm font-[Helvetica] font-bold">
                <input type="radio" name="provisioning_mode" value="direct" x-model="provisioningMode">
                <span>Aktifkan Langsung<span class="mt-1 block font-['Times_New_Roman'] text-xs font-normal">Superadmin membuat akun aktif dengan password sementara. Pengguna wajib mengganti password saat login pertama.</span></span>
            </label>
            <div x-cloak x-show="provisioningMode === 'direct'" class="grid gap-4 border-t-2 border-black pt-4 md:grid-cols-2">
                <div><label for="temporary_password" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Password Sementara</label><input id="temporary_password" type="password" name="temporary_password" :required="provisioningMode === 'direct'" autocomplete="new-password" class="w-full rounded-none border-2 border-black px-3 py-2 text-sm">@error('temporary_password')<p class="mt-1 text-xs font-bold text-[#e91d2a]">{{ $message }}</p>@enderror</div>
                <div><label for="temporary_password_confirmation" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Konfirmasi Password Sementara</label><input id="temporary_password_confirmation" type="password" name="temporary_password_confirmation" :required="provisioningMode === 'direct'" autocomplete="new-password" class="w-full rounded-none border-2 border-black px-3 py-2 text-sm"></div>
            </div>
            @endif
        </div>
    </fieldset>

    <div x-show="provisioningMode === 'invitation'" class="flex flex-wrap gap-2"><button name="submit_action" value="draft" class="bg-white border-2 border-black px-4 py-2 text-xs font-bold">SIMPAN DRAF</button><button name="submit_action" value="send" class="bg-[#8c9ae0] border-2 border-black px-4 py-2 text-xs font-bold">SIMPAN &amp; KIRIM UNDANGAN</button><a href="{{ route('admin-users.index') }}" class="bg-white border-2 border-black px-4 py-2 text-xs font-bold">BATAL</a></div>
    @if(Auth::user()->isSuperadmin())
    <div x-cloak x-show="provisioningMode === 'direct'" class="flex flex-wrap gap-2"><button name="submit_action" value="activate" class="bg-[#8c9ae0] border-2 border-black px-4 py-2 text-xs font-bold">AKTIFKAN AKUN</button><a href="{{ route('admin-users.index') }}" class="bg-white border-2 border-black px-4 py-2 text-xs font-bold">BATAL</a></div>
    @endif
</form></div>
@endsection
