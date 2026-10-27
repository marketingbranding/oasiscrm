@extends('layouts.crm')
@section('title', 'Ajukan Onboarding Sales - Oasis CRM')
@section('content')
<x-crm.page-header color="#fcc20f" title="Ajukan Onboarding Sales" />
<div class="border-2 border-black bg-white">
    <div class="bg-black px-3 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">Proposal Sales</div>
    <form method="POST" action="{{ route('admin-users.sales-proposals.store') }}" class="grid gap-4 p-4 md:grid-cols-2">@csrf
        <label class="text-xs font-bold uppercase">Nama Lengkap<input name="name" value="{{ old('name') }}" required class="mt-1 w-full border-2 border-black px-3 py-2 text-sm">@error('name')<span class="mt-1 block text-xs text-[#c0392b]">{{ $message }}</span>@enderror</label>
        <label class="text-xs font-bold uppercase">Email<input type="email" name="email" value="{{ old('email') }}" required class="mt-1 w-full border-2 border-black px-3 py-2 text-sm">@error('email')<span class="mt-1 block text-xs text-[#c0392b]">{{ $message }}</span>@enderror</label>
        <label class="text-xs font-bold uppercase">Telepon<input name="phone" value="{{ old('phone') }}" class="mt-1 w-full border-2 border-black px-3 py-2 text-sm">@error('phone')<span class="mt-1 block text-xs text-[#c0392b]">{{ $message }}</span>@enderror</label>
        <input type="hidden" name="role_id" value="{{ $salesRole }}">
        <label class="text-xs font-bold uppercase">KC/KCP<select name="branch_id" required class="mt-1 w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Pilih KC/KCP</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }} ({{ $branch->code }})</option>@endforeach</select>@error('branch_id')<span class="mt-1 block text-xs text-[#c0392b]">{{ $message }}</span>@enderror</label>
        <label class="text-xs font-bold uppercase">Proyek<select name="project_id" required class="mt-1 w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Pilih proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(old('project_id') == $project->id)>{{ $project->project_name }} — {{ $project->branch?->name }}</option>@endforeach</select>@error('project_id')<span class="mt-1 block text-xs text-[#c0392b]">{{ $message }}</span>@enderror</label>
        <label class="text-xs font-bold uppercase">Koordinator Sales<select name="coordinator_user_id" required class="mt-1 w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Pilih Koordinator</option>@foreach($coordinators as $coordinator)<option value="{{ $coordinator->id }}" @selected(old('coordinator_user_id') == $coordinator->id)>{{ $coordinator->name }}</option>@endforeach</select>@error('coordinator_user_id')<span class="mt-1 block text-xs text-[#c0392b]">{{ $message }}</span>@enderror</label>
        <p class="md:col-span-2 border-2 border-black bg-[#fff3b0] p-3 text-sm">Proposal akan menunggu persetujuan Superadmin. Akun dan undangan tidak dibuat sebelum disetujui.</p>
        <div class="flex flex-wrap gap-2 md:col-span-2"><button class="border-2 border-black bg-[#fcc20f] px-4 py-2 text-xs font-bold">AJUKAN PROPOSAL</button><a href="{{ route('admin-users.index') }}" class="border-2 border-black bg-white px-4 py-2 text-xs font-bold">BATAL</a></div>
    </form>
</div>
@endsection
