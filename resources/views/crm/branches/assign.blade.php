@extends('layouts.crm')

@section('title', 'Atur Admin - Oasis CRM')

@section('content')
    <x-crm.page-header color="#8c9ae0" title="{{ $branch->name }}" />

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Current Admins --}}
        <div class="border-2 border-black">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">
                Admin Saat Ini
            </div>
            @if($admins->count() > 0)
            <div class="divide-y-2 divide-black">
                @foreach($admins as $admin)
                <div class="px-3 py-3 flex items-center justify-between">
                    <div class="text-sm font-['Times_New_Roman']">
                        <div class="font-bold">{{ $admin->name }}</div>
                        <div class="text-xs">{{ $admin->email }}</div>
                    </div>
                    <form method="POST" action="{{ route('branches.remove-admin', $admin->id) }}" onsubmit="return confirm('Yakin ingin menghapus admin ini?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bg-white text-[#e91d2a] px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-[#e91d2a] rounded-none hover:bg-red-50">
                            Hapus
                        </button>
                    </form>
                </div>
                @endforeach
            </div>
            @else
            <div class="px-4 py-6 text-center text-sm font-['Times_New_Roman']">
                Belum ada admin untuk cabang ini.
            </div>
            @endif
        </div>

        {{-- Add Admin --}}
        <div class="border-2 border-black bg-white">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">
                Tambah Admin Baru
            </div>
            <div class="p-4">
                <form method="POST" action="{{ route('branches.assign-store', $branch->id) }}" class="space-y-4">
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

                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Tambah Admin
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <a href="{{ route('branches.index') }}" class="text-[#0000ee] underline text-sm font-[Helvetica] font-bold">
            ← Kembali ke daftar cabang
        </a>
    </div>
@endsection
