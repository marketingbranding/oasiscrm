@extends('layouts.crm')
@section('title', 'Undang Pengguna - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Undang Pengguna" />
<div class="border-2 border-black bg-white"><div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Data Akun dan Organisasi</div>
<form method="POST" action="{{ route('admin-users.store') }}" class="p-4 space-y-4">@csrf
    @include('crm.admin-users._form')
    <label class="flex items-center gap-2 text-sm font-[Helvetica] font-bold"><input type="checkbox" name="send_immediately" value="1" @checked(old('send_immediately'))> Kirim segera setelah disimpan</label>
    <p class="text-xs font-['Times_New_Roman']">Kata sandi dibuat sendiri oleh pengguna melalui tautan undangan. Admin tidak dapat melihat atau menentukan kata sandi.</p>
    <div class="flex flex-wrap gap-2"><button name="submit_action" value="draft" class="bg-white border-2 border-black px-4 py-2 text-xs font-bold">SAVE DRAFT</button><button name="submit_action" value="send" class="bg-[#8c9ae0] border-2 border-black px-4 py-2 text-xs font-bold">SAVE &amp; SEND INVITATION</button><a href="{{ route('admin-users.index') }}" class="bg-white border-2 border-black px-4 py-2 text-xs font-bold">CANCEL</a></div>
</form></div>
@endsection
