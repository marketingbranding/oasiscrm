@extends('layouts.crm')

@section('title', 'Peran dan Aturan Organisasi')

@section('content')
<div class="space-y-5 p-4 md:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b-2 border-black pb-4">
        <div><p class="font-[Helvetica] text-xs font-bold uppercase tracking-[0.16em] text-gray-600">Administrasi / Akses</p><h1 class="mt-1 font-['Arial_Black'] text-2xl uppercase md:text-3xl">Peran dan Aturan Organisasi</h1><p class="mt-1 text-sm text-gray-700">Permission tetap berasal dari registry aplikasi. Aturan reporting mengatur parent-child, bukan hak akses data.</p></div>
        <a href="{{ route('organization.index') }}" class="border-2 border-black bg-white px-3 py-2 font-[Helvetica] text-sm font-bold hover:bg-[var(--oasis-yellow)]">Buka Struktur Organisasi</a>
    </div>

    <section class="border-2 border-black bg-white p-4">
        <h2 class="font-[Helvetica] text-sm font-bold uppercase">Buat custom role</h2>
        <form method="POST" action="{{ route('roles.store') }}" class="mt-3 grid gap-3 md:grid-cols-4">
            @csrf
            <input name="name" required placeholder="Nama role" class="border-2 border-black px-3 py-2">
            <input name="slug" required placeholder="slug_role" class="border-2 border-black px-3 py-2">
            <input name="authority_level" required type="number" min="0" max="1000" value="0" placeholder="Authority" class="border-2 border-black px-3 py-2">
            <input name="description" placeholder="Deskripsi" class="border-2 border-black px-3 py-2">
            <button class="border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-[Helvetica] text-sm font-bold md:col-span-4 md:w-fit">Buat Role</button>
        </form>
    </section>

    <section class="space-y-4">
        @foreach($roles as $role)
            <article class="border-2 border-black bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b-2 border-black pb-3">
                    <div><h2 class="text-lg font-bold">{{ $role->name }} <span class="font-[Helvetica] text-xs font-normal text-gray-600">({{ $role->slug }})</span></h2><p class="text-xs text-gray-600">{{ $role->users_count }} pengguna · {{ $role->is_superadmin ? 'wildcard registered permissions' : 'permission eksplisit' }}</p></div>
                    <form method="POST" action="{{ route('roles.update', $role) }}" class="flex flex-wrap items-center gap-2 font-[Helvetica] text-xs">
                        @csrf @method('PUT')
                        <input name="name" value="{{ $role->name }}" aria-label="Nama {{ $role->name }}" class="w-36 border border-black px-2 py-1">
                        <input name="description" value="{{ $role->description }}" aria-label="Deskripsi {{ $role->name }}" class="w-44 border border-black px-2 py-1">
                        <input name="authority_level" type="number" min="0" max="1000" value="{{ $role->authority_level }}" aria-label="Authority {{ $role->name }}" class="w-20 border border-black px-2 py-1">
                        <input type="hidden" name="is_active" value="{{ $role->is_active ? 1 : 0 }}">
                        <button class="border border-black px-2 py-1 font-bold hover:bg-[var(--oasis-yellow)]">Simpan metadata</button>
                    </form>
                </div>

                @if(!$role->is_superadmin)
                    <form method="POST" action="{{ route('roles.permissions.update', $role) }}" class="mt-4">
                        @csrf @method('PUT')
                        <div class="grid gap-4 md:grid-cols-3">
                            @foreach($permissions as $group => $groupPermissions)
                                <fieldset class="border border-gray-400 p-3"><legend class="px-1 font-[Helvetica] text-xs font-bold uppercase">{{ $group }}</legend><div class="grid gap-1">
                                    @foreach($groupPermissions as $permission)
                                        <label class="flex items-start gap-2 text-xs"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked($role->permissions->contains('id', $permission->id))><span><strong>{{ $permission->slug }}</strong><br><span class="text-gray-600">{{ $permission->description }}</span></span></label>
                                    @endforeach
                                </div></fieldset>
                            @endforeach
                        </div>
                        <button class="mt-3 border-2 border-black bg-white px-3 py-2 font-[Helvetica] text-xs font-bold hover:bg-[var(--oasis-yellow)]">Simpan permission terdaftar</button>
                    </form>
                @endif
            </article>
        @endforeach
    </section>

    <section class="overflow-x-auto border-2 border-black bg-white p-4">
        <h2 class="font-[Helvetica] text-sm font-bold uppercase">Role reporting rules</h2>
        <form method="POST" action="{{ route('roles.reporting-rules.update') }}" class="mt-3 min-w-[48rem]">
            @csrf @method('PATCH')
            <table class="w-full border-collapse text-left text-xs"><thead class="bg-black text-white"><tr><th class="p-2">Parent</th><th class="p-2">Child</th><th class="p-2">Allowed</th></tr></thead><tbody>
                @php($ruleIndex = 0)
                @foreach($roles as $parent)
                    @foreach($roles as $child)
                        @php($key = "{$parent->id}:{$child->id}")
                        @php($rule = $rules->get($key))
                        <tr class="border-b border-gray-300"><td class="p-2">{{ $parent->name }}</td><td class="p-2">{{ $child->name }}</td><td class="p-2">
                            <input type="hidden" name="rules[{{ $ruleIndex }}][parent_role_id]" value="{{ $parent->id }}"><input type="hidden" name="rules[{{ $ruleIndex }}][child_role_id]" value="{{ $child->id }}"><input type="hidden" name="rules[{{ $ruleIndex }}][is_allowed]" value="0"><input type="checkbox" name="rules[{{ $ruleIndex }}][is_allowed]" value="1" @checked($rule?->is_allowed) aria-label="{{ $parent->name }} ke {{ $child->name }}">
                        </td></tr>
                        @php($ruleIndex++)
                    @endforeach
                @endforeach
            </tbody></table>
            <button class="mt-3 border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-[Helvetica] text-xs font-bold">Simpan reporting rules</button>
        </form>
    </section>
</div>
@endsection
