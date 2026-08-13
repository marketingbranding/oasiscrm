@extends('layouts.crm')
@section('title', 'Manajemen Pengguna - Oasis CRM')
@section('content')
@php
    $canBulkResetAccess = auth()->user()->isSuperadmin();
    $visibleBulkUserIds = $canBulkResetAccess
        ? $users->getCollection()->where('id', '!=', auth()->id())->pluck('id')->map(fn ($id) => (string) $id)->values()
        : collect();
@endphp
<div x-data="{
    filters: false,
    selected: [],
    visibleIds: @js($visibleBulkUserIds),
    allVisibleSelected() { return this.visibleIds.length > 0 && this.visibleIds.every(id => this.selected.includes(id)) },
    toggleVisible() { this.selected = this.allVisibleSelected() ? this.selected.filter(id => !this.visibleIds.includes(id)) : [...new Set([...this.selected, ...this.visibleIds])] }
}">
<x-crm.page-header color="#8c9ae0" title="Manajemen Pengguna" />
<div class="border-2 border-black bg-white p-3 mb-4 flex flex-wrap items-center gap-2">
    <form method="GET" class="flex min-w-64 flex-1"><input name="search" value="{{ request('search') }}" placeholder="Cari nama atau email" class="w-full border-2 border-black px-3 py-2 text-sm"><input type="hidden" name="account_status" value="{{ request('account_status') }}"><input type="hidden" name="role_id" value="{{ request('role_id') }}"><input type="hidden" name="branch_id" value="{{ request('branch_id') }}"><input type="hidden" name="project_id" value="{{ request('project_id') }}"><input type="hidden" name="supervisor_user_id" value="{{ request('supervisor_user_id') }}"><input type="hidden" name="invitation_status" value="{{ request('invitation_status') }}"><button class="bg-black text-white border-2 border-black px-4 text-xs font-bold">CARI</button></form>
    @php($activeFilters = collect(['account_status','role_id','branch_id','project_id','supervisor_user_id','invitation_status'])->filter(fn($key) => filled(request($key))))
    <button @click="filters=true" class="border-2 border-black bg-white px-4 py-2 text-xs font-bold">FILTER{{ $activeFilters->isNotEmpty() ? ' ('.$activeFilters->count().')' : '' }}</button>
    @if(auth()->user()->hasAllPermissions(\App\Policies\UserImportBatchPolicy::REQUIRED_PERMISSIONS))<a href="{{ route('admin-users.import') }}" class="border-2 border-black bg-[#8c9ae0] px-4 py-2 text-xs font-bold">IMPORT USER XLSX</a>@endif
    @can('users.create')<a href="{{ route('admin-users.create') }}" class="border-2 border-black bg-[#8c9ae0] px-4 py-2 text-xs font-bold">+ TAMBAH</a>@endcan
</div>
@if($activeFilters->isNotEmpty())<div class="mb-4 flex flex-wrap gap-2 text-xs font-[Helvetica]">@foreach($activeFilters as $key)<span class="border-2 border-black bg-[#fff3b0] px-2 py-1">{{ str_replace('_', ' ', strtoupper($key)) }}: {{ request($key) }}</span>@endforeach<a href="{{ route('admin-users.index', ['search' => request('search')]) }}" class="font-bold underline px-2 py-1">Hapus semua filter</a></div>@endif

<div class="crm-table-scroll"><table class="crm-data-table"><thead><tr>
@if($canBulkResetAccess)<th class="w-12"><input type="checkbox" :checked="allVisibleSelected()" @change="toggleVisible()" :disabled="visibleIds.length === 0" aria-label="Pilih semua pengguna yang terlihat" class="size-5"></th>@endif
@foreach(['name'=>'Name','email'=>'Email'] as $key=>$label)<th><a href="{{ request()->fullUrlWithQuery(['sort'=>$key,'direction'=>request('sort')===$key && request('direction')==='asc'?'desc':'asc','page'=>null]) }}">{{ $label }} @if(request('sort')===$key){{ request('direction')==='desc'?'▼':'▲' }}@endif</a></th>@endforeach
<th>Role</th><th>Primary Branch</th><th>Projects</th><th>Supervisor</th><th><a href="{{ request()->fullUrlWithQuery(['sort'=>'account_status','direction'=>request('sort')==='account_status' && request('direction')==='asc'?'desc':'asc','page'=>null]) }}">Account Status</a></th><th><a href="{{ request()->fullUrlWithQuery(['sort'=>'last_login_at','direction'=>request('sort')==='last_login_at' && request('direction')==='asc'?'desc':'asc','page'=>null]) }}">Last Login</a></th><th>Actions</th></tr></thead>
<tbody>@forelse($users as $user)<tr>@if($canBulkResetAccess)<td>@if($user->id !== auth()->id())<input type="checkbox" value="{{ $user->id }}" x-model="selected" aria-label="Pilih {{ $user->name }}" class="size-5">@endif</td>@endif<td class="font-bold"><a class="text-[#0000ee] underline" href="{{ route('admin-users.show',$user) }}">{{ $user->name }}</a></td><td>{{ $user->email }}</td><td>{{ $user->role?->name ?? '-' }}</td><td>{{ $user->branch?->name ?? '-' }}</td><td title="{{ $user->assignedProjects->pluck('project_name')->join(', ') }}">{{ $user->assignedProjects->pluck('project_name')->join(', ') ?: '-' }}</td><td>{{ $user->supervisor?->name ?? '-' }}</td><td><span class="border border-black px-2 py-0.5 font-bold">{{ str_replace('_',' ',strtoupper($user->account_status->value)) }}</span></td><td>{{ $user->last_login_at?->format('d/m/Y H:i') ?? '-' }}</td><td class="whitespace-nowrap"><a class="text-[#0000ee] font-bold underline" href="{{ route('admin-users.show',$user) }}">View</a> @can('update',$user) <a class="text-[#0000ee] font-bold underline ml-2" href="{{ route('admin-users.edit',$user) }}">Edit</a>@endcan</td></tr>@empty<tr><td colspan="{{ $canBulkResetAccess ? 10 : 9 }}" class="text-center">Tidak ada pengguna.</td></tr>@endforelse</tbody></table></div>
<div class="mt-4">{{ $users->links() }}</div>

@if($canBulkResetAccess)
<div x-cloak x-show="selected.length" class="sticky bottom-0 z-20 mt-4 flex items-center justify-between gap-4 border-2 border-black bg-white p-3">
    <strong class="text-sm"><span x-text="selected.length"></span> pengguna dipilih</strong>
    <x-crm.button type="button" variant="primary" @click="$dispatch('oasis:modal-open', { name: 'bulk-reset-access', trigger: $el })">Reset / Aktifkan Akses</x-crm.button>
</div>
<x-crm.modal name="bulk-reset-access" title="Konfirmasi Reset / Aktifkan Akses" description="Tindakan ini mengganti akses pengguna terpilih." size="sm">
    <form method="POST" action="{{ route('admin-users.bulk-reset-access') }}">
        @csrf
        <template x-for="id in selected" :key="id"><input type="hidden" name="user_ids[]" :value="id"></template>
        <div class="space-y-3 text-sm">
            <p><strong x-text="selected.length"></strong> pengguna akan direset atau diaktifkan aksesnya.</p>
            <ul class="list-disc space-y-1 pl-5">
                <li>Password awal: <strong>password</strong>.</li>
                <li>Pengguna wajib mengganti password saat login pertama.</li>
                <li>Email undangan tidak dikirim.</li>
                <li class="font-bold text-[#c0392b]">Password lama tidak lagi berlaku.</li>
            </ul>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <x-crm.button type="button" variant="secondary" @click="$dispatch('oasis:modal-close', { name: 'bulk-reset-access' })">Batal</x-crm.button>
            <x-crm.button type="submit" variant="primary">Reset / Aktifkan Akses</x-crm.button>
        </div>
    </form>
</x-crm.modal>
@endif

<div x-cloak x-show="filters" @keydown.escape.window="filters=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><div @click.outside="filters=false" class="bg-white border-2 border-black w-full max-w-2xl max-h-[90vh] overflow-y-auto"><div class="bg-black text-white p-3 flex justify-between font-bold"><span>FILTER PENGGUNA</span><button @click="filters=false" type="button">X</button></div><form method="GET" class="p-4 grid gap-4 sm:grid-cols-2"><input type="hidden" name="search" value="{{ request('search') }}">
<label class="text-xs font-bold">STATUS AKUN<select name="account_status" class="mt-1 w-full border-2 border-black p-2 bg-white"><option value="">Semua</option>@foreach(\App\Enums\AccountStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('account_status')===$status->value)>{{ str_replace('_',' ',ucwords($status->value)) }}</option>@endforeach</select></label>
<label class="text-xs font-bold">PERAN<select name="role_id" class="mt-1 w-full border-2 border-black p-2 bg-white"><option value="">Semua</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(request('role_id')==$role->id)>{{ $role->name }}</option>@endforeach</select></label>
<label class="text-xs font-bold">CABANG<select name="branch_id" class="mt-1 w-full border-2 border-black p-2 bg-white"><option value="">Semua</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
<label class="text-xs font-bold">PROYEK<select name="project_id" class="mt-1 w-full border-2 border-black p-2 bg-white"><option value="">Semua</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(request('project_id')==$project->id)>{{ $project->project_name }} - {{ $project->branch?->name }}</option>@endforeach</select></label>
<label class="text-xs font-bold">ATASAN<select name="supervisor_user_id" class="mt-1 w-full border-2 border-black p-2 bg-white"><option value="">Semua</option>@foreach($supervisors as $supervisor)<option value="{{ $supervisor->id }}" @selected(request('supervisor_user_id')==$supervisor->id)>{{ $supervisor->name }}</option>@endforeach</select></label>
<label class="text-xs font-bold">STATUS UNDANGAN<select name="invitation_status" class="mt-1 w-full border-2 border-black p-2 bg-white"><option value="">Semua</option>@foreach(['draft'=>'Draft','usable'=>'Dapat digunakan','expired'=>'Kedaluwarsa','accepted'=>'Diterima','revoked'=>'Dicabut'] as $value=>$label)<option value="{{ $value }}" @selected(request('invitation_status')===$value)>{{ $label }}</option>@endforeach</select></label>
<div class="sm:col-span-2 flex gap-2"><button class="bg-[#8c9ae0] border-2 border-black px-4 py-2 text-xs font-bold">TERAPKAN FILTER</button><a href="{{ route('admin-users.index',['search'=>request('search')]) }}" class="bg-white border-2 border-black px-4 py-2 text-xs font-bold">RESET FILTER</a></div></form></div></div>
</div>
@endsection
