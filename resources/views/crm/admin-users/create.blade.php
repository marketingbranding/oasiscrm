@extends('layouts.crm')

@section('title', 'Tambah User - Oasis CRM')

@section('content')
    <x-crm.page-header color="#8c9ae0" title="Tambah User Baru" />

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Tambah User
        </div>
        <div class="p-4">
            <form method="POST" action="{{ route('admin-users.store') }}" class="space-y-4 max-w-xl"
                x-data="{ role: @js($roles->firstWhere('id', old('role_id'))?->slug ?? '') }">
                @csrf

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama</label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                        class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('name') border-[#e91d2a] @enderror">
                    @error('name')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required
                        class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('email') border-[#e91d2a] @enderror">
                    @error('email')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Password</label>
                    <input type="password" name="password" required
                        class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('password') border-[#e91d2a] @enderror">
                    @error('password')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Konfirmasi Password</label>
                    <input type="password" name="password_confirmation" required
                        class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Role</label>
                    <select name="role_id" required @change="role = $event.target.selectedOptions[0]?.dataset.slug || ''" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('role_id') border-[#e91d2a] @enderror">
                        <option value="">— Pilih Role —</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" data-slug="{{ $role->slug }}" {{ old('role_id') == $role->id ? 'selected' : '' }}>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    @error('role_id')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang Utama</label>
                    <select name="branch_id" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('branch_id') border-[#e91d2a] @enderror">
                        <option value="">— Tidak Ada —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }} @if(str_contains(mb_strtolower($branch->name), 'pusat')) style="color:#b8860b;font-weight:700;background:#fff3b0" @endif>{{ $branch->name }} ({{ $branch->code }})</option>
                        @endforeach
                    </select>
                    @error('branch_id')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                @include('crm.admin-users._memberships')

                @include('crm.admin-users._project-assignments')

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Telepon</label>
                    <input type="text" name="phone" value="{{ old('phone') }}"
                        class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('phone') border-[#e91d2a] @enderror">
                    @error('phone')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan
                    </button>
                    <a href="{{ route('admin-users.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
