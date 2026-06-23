@extends('layouts.crm')

@section('title', 'Manajemen User - Oasis CRM')

@section('content')
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6 flex items-center justify-between">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Manajemen User</h1>
        <a href="{{ route('admin-users.create') }}" class="bg-[#8c9ae0] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#7a8ad4]">
            + Tambah User
        </a>
    </div>

    <div class="border-2 border-black">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Daftar User
        </div>
        @if($users->count() > 0)
        <div class="divide-y-2 divide-black">
            @foreach($users as $user)
            <div class="px-3 py-3 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 border-2 border-black flex items-center justify-center font-[Helvetica] font-bold text-sm {{ $user->isSuperadmin() ? 'bg-[#fcc20f]' : 'bg-[#9ab6c8]' }}">
                        {{ strtoupper(substr($user->name, 0, 2)) }}
                    </div>
                    <div class="text-sm font-['Times_New_Roman']">
                        <div class="font-bold">{{ $user->name }}</div>
                        <div class="text-xs">{{ $user->email }}</div>
                        <div class="text-xs mt-0.5">
                            <span class="font-bold">{{ $user->role->name ?? '-' }}</span>
                            @if($user->branch)
                                <span class="text-gray-600">— {{ $user->branch->name }} ({{ $user->branch->code }})</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @if(!$user->is_active)
                        <span class="bg-[#d77a7a] text-white px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black">Nonaktif</span>
                    @endif
                    <a href="{{ route('admin-users.edit', $user) }}" class="bg-white text-black px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Edit
                    </a>
                    <form method="POST" action="{{ route('admin-users.destroy', $user) }}" onsubmit="return confirm('Yakin ingin menghapus user ini?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bg-white text-[#e91d2a] px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-[#e91d2a] rounded-none hover:bg-red-50">
                            Hapus
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @else
        <div class="px-4 py-8 text-center text-sm font-['Times_New_Roman']">
            Belum ada user.
        </div>
        @endif
    </div>
@endsection
