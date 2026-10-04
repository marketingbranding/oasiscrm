@extends('layouts.crm')

@section('title', 'Peran dan Aturan Organisasi')

@section('content')
<div class="space-y-5 p-4 md:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b-2 border-black pb-4">
        <div class="max-w-3xl">
            <p class="font-[Helvetica] text-xs font-bold uppercase tracking-[0.16em] text-gray-600">Administrasi / Akses</p>
            <h1 class="mt-1 font-['Arial_Black'] text-2xl uppercase md:text-3xl">Peran dan Aturan Organisasi</h1>
            <p class="mt-1 text-sm text-gray-700">Atur kemampuan setiap peran dan tentukan hubungan atasan-bawahan. Nama izin menjelaskan tindakan yang boleh dilakukan; penjelasannya menunjukkan data yang terkena.</p>
        </div>
        <a href="{{ route('organization.index') }}" class="border-2 border-black bg-white px-3 py-2 font-[Helvetica] text-sm font-bold hover:bg-[var(--oasis-yellow)]">Buka Struktur Organisasi</a>
    </div>

    <section class="border-2 border-black bg-white p-4">
        <h2 class="font-[Helvetica] text-sm font-bold uppercase">Buat peran baru</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-600">Peran adalah kumpulan izin untuk sekelompok pengguna. Kode peran hanya dipakai sistem dan tidak menjadi label utama di halaman pengguna.</p>
        <form method="POST" action="{{ route('roles.store') }}" class="mt-4 grid gap-3 md:grid-cols-4">
            @csrf
            <label class="flex flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">Nama peran
                <input name="name" required placeholder="Contoh: Koordinator Lapangan" class="border-2 border-black px-3 py-2 text-sm font-normal">
            </label>
            <label class="flex flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">Kode teknis peran
                <input name="slug" required placeholder="koordinator_lapangan" class="border-2 border-black px-3 py-2 text-sm font-normal">
            </label>
            <label class="flex flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">Tingkat kewenangan
                <input name="authority_level" required type="number" min="0" max="1000" value="0" class="border-2 border-black px-3 py-2 text-sm font-normal">
            </label>
            <label class="flex flex-col gap-1 font-[Helvetica] text-xs font-bold uppercase">Deskripsi singkat
                <input name="description" placeholder="Contoh: Mengatur tim sales di cabang." class="border-2 border-black px-3 py-2 text-sm font-normal">
            </label>
            <button class="border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-[Helvetica] text-sm font-bold md:col-span-4 md:w-fit">Buat peran</button>
        </form>
    </section>

    <section class="space-y-4">
        @foreach($roles as $role)
            <article class="border-2 border-black bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b-2 border-black pb-3">
                    <div class="min-w-60">
                        <h2 class="text-lg font-bold">{{ $role->name }}</h2>
                        <p class="mt-1 max-w-xl text-sm text-gray-700">{{ $role->description ?: 'Belum ada deskripsi peran.' }}</p>
                        <p class="mt-2 text-xs text-gray-600">{{ $role->users_count }} pengguna - {{ $role->is_superadmin ? 'Semua izin terdaftar aktif otomatis.' : 'Izin dipilih secara khusus untuk peran ini.' }}</p>
                        <details class="mt-2 font-[Helvetica] text-[11px] text-gray-500">
                            <summary class="cursor-pointer font-bold uppercase">Lihat kode peran</summary>
                            <code class="mt-1 block">{{ $role->slug }}</code>
                        </details>
                    </div>
                    <form method="POST" action="{{ route('roles.update', $role) }}" class="grid w-full gap-2 font-[Helvetica] text-xs sm:grid-cols-2 lg:w-auto lg:grid-cols-4">
                        @csrf @method('PUT')
                        <label class="flex flex-col gap-1 font-bold uppercase">Nama peran
                            <input name="name" value="{{ $role->name }}" aria-label="Nama {{ $role->name }}" class="border border-black px-2 py-1 font-normal">
                        </label>
                        <label class="flex flex-col gap-1 font-bold uppercase">Deskripsi
                            <input name="description" value="{{ $role->description }}" aria-label="Deskripsi {{ $role->name }}" class="border border-black px-2 py-1 font-normal">
                        </label>
                        <label class="flex flex-col gap-1 font-bold uppercase">Tingkat kewenangan
                            <input name="authority_level" type="number" min="0" max="1000" value="{{ $role->authority_level }}" aria-label="Tingkat kewenangan {{ $role->name }}" class="border border-black px-2 py-1 font-normal">
                        </label>
                        <input type="hidden" name="is_active" value="{{ $role->is_active ? 1 : 0 }}">
                        <button class="border border-black px-2 py-1 font-bold hover:bg-[var(--oasis-yellow)]">Simpan perubahan peran</button>
                    </form>
                </div>

                @if(!$role->is_superadmin)
                    <form method="POST" action="{{ route('roles.permissions.update', $role) }}" class="mt-4">
                        @csrf @method('PUT')
                        <div class="border-2 border-[#d6cdb4] bg-[#faf8f0] p-3">
                            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-[#d6cdb4] pb-3">
                                <div>
                                    <h3 class="font-[Helvetica] text-sm font-bold uppercase">Izin akses peran</h3>
                                    <p class="mt-1 max-w-2xl text-sm text-gray-600">Centang kemampuan yang boleh dilakukan peran ini. Izin dengan lingkup sendiri, tim, cabang, atau semua data menentukan seberapa luas datanya.</p>
                                </div>
                                <span class="border border-black bg-white px-2 py-1 font-[Helvetica] text-xs font-bold">{{ $role->permissions->count() }} izin aktif</span>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-3 lg:grid-cols-2">
                            @foreach($permissions as $group => $groupPermissions)
                                @php($selectedCount = $groupPermissions->filter(fn ($permission) => $role->permissions->contains('id', $permission->id))->count())
                                <details class="border-2 border-black bg-white" @if($selectedCount > 0) open @endif>
                                    <summary class="flex cursor-pointer list-none items-start justify-between gap-3 bg-[#f3edda] px-3 py-3 font-[Helvetica]">
                                        <span><strong class="block text-xs uppercase">{{ $group }}</strong><span class="mt-1 block max-w-md text-xs font-normal normal-case text-gray-600">{{ $permissionGroupDescriptions[$group] ?? 'Izin yang berkaitan dengan bagian ini.' }}</span></span>
                                        <span class="shrink-0 border border-black bg-white px-2 py-1 text-[10px] font-bold uppercase">{{ $selectedCount }}/{{ $groupPermissions->count() }} aktif</span>
                                    </summary>
                                    <fieldset class="grid gap-3 border-t-2 border-black p-3">
                                        <legend class="sr-only">{{ $group }}</legend>
                                        @foreach($groupPermissions as $permission)
                                            <div class="flex items-start gap-3">
                                                <input id="role-{{ $role->id }}-permission-{{ $permission->id }}" type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked($role->permissions->contains('id', $permission->id)) class="mt-1" aria-describedby="permission-description-{{ $role->id }}-{{ $permission->id }}">
                                                <div class="min-w-0">
                                                    <label for="role-{{ $role->id }}-permission-{{ $permission->id }}" class="cursor-pointer text-sm font-bold">{{ $permission->name }}</label>
                                                    <p id="permission-description-{{ $role->id }}-{{ $permission->id }}" class="mt-1 text-sm text-gray-600">{{ $permission->description }}</p>
                                                    <details class="mt-1 font-[Helvetica] text-[11px] text-gray-500">
                                                        <summary class="cursor-pointer font-bold uppercase">Lihat kode sistem</summary>
                                                        <code class="mt-1 block">{{ $permission->slug }}</code>
                                                    </details>
                                                </div>
                                            </div>
                                        @endforeach
                                    </fieldset>
                                </details>
                            @endforeach
                        </div>
                        <button class="mt-3 border-2 border-black bg-white px-3 py-2 font-[Helvetica] text-xs font-bold hover:bg-[var(--oasis-yellow)]">Simpan izin akses</button>
                    </form>
                @else
                    <div class="mt-4 border-2 border-[#d6cdb4] bg-[#faf8f0] p-3 text-sm text-gray-700"><strong>Super Admin</strong> otomatis memiliki semua izin yang terdaftar di aplikasi. Matrix checkbox tidak ditampilkan agar tidak memberi kesan izinnya bisa dibatasi dari halaman ini.</div>
                @endif
            </article>
        @endforeach
    </section>

    <section class="overflow-x-auto border-2 border-black bg-white p-4">
        @php($allowedRuleCount = $rules->filter(fn ($rule) => $rule->is_allowed)->count())
        <h2 class="font-[Helvetica] text-sm font-bold uppercase">Aturan hubungan atasan dan bawahan</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-700">Tentukan peran mana yang boleh menjadi atasan bagi peran lain. Aturan ini dipakai saat menyusun Struktur Organisasi; aturan ini tidak memberikan izin akses data.</p>
        <p class="mt-2 font-[Helvetica] text-xs font-bold uppercase text-gray-600">{{ $allowedRuleCount }} hubungan saat ini diizinkan</p>
        <form method="POST" action="{{ route('roles.reporting-rules.update') }}" class="mt-3 min-w-[48rem]">
            @csrf @method('PATCH')
            <table class="w-full border-collapse text-left text-sm"><thead class="bg-black text-white"><tr><th class="p-2">Peran atasan</th><th class="p-2">Peran bawahan</th><th class="p-2">Hubungan diizinkan</th></tr></thead><tbody>
            @php($ruleIndex = 0)
            @foreach($roles as $parent)
                @foreach($roles as $child)
                    @if($parent->id === $child->id)
                        @continue
                    @endif
                    @php($key = "{$parent->id}:{$child->id}")
                    @php($rule = $rules->get($key))
                    <tr class="border-b border-gray-300"><td class="p-2">{{ $parent->name }}</td><td class="p-2">{{ $child->name }}</td><td class="p-2">
                        <label class="inline-flex min-h-11 items-center gap-2 font-[Helvetica] text-xs font-bold"><input type="hidden" name="rules[{{ $ruleIndex }}][parent_role_id]" value="{{ $parent->id }}"><input type="hidden" name="rules[{{ $ruleIndex }}][child_role_id]" value="{{ $child->id }}"><input type="hidden" name="rules[{{ $ruleIndex }}][is_allowed]" value="0"><input type="checkbox" name="rules[{{ $ruleIndex }}][is_allowed]" value="1" @checked($rule?->is_allowed) aria-label="Izinkan {{ $parent->name }} membawahi {{ $child->name }}"><span>Boleh membawahi</span></label>
                    </td></tr>
                    @php($ruleIndex++)
                @endforeach
            @endforeach
            </tbody></table>
            <button class="mt-3 border-2 border-black bg-[var(--oasis-yellow)] px-3 py-2 font-[Helvetica] text-xs font-bold">Simpan aturan hubungan</button>
        </form>
    </section>
</div>
@endsection
