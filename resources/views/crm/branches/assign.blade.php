@extends('layouts.crm')

@section('title', 'Akses Cabang - Oasis CRM')

@section('content')
    <x-crm.page-header color="#8c9ae0" title="Akses Cabang: {{ $branch->name }}" />

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="border-2 border-black">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Anggota Cabang</div>
            @forelse($members as $member)
                <div class="border-b-2 last:border-b-0 border-black px-3 py-3 flex items-center justify-between gap-3">
                    <div class="text-sm font-['Times_New_Roman']">
                        <div class="font-bold">{{ $member->name }}</div>
                        <div class="text-xs">{{ $member->email }} · {{ $member->role?->name ?? 'Tanpa role' }}</div>
                        <div class="text-[10px] font-[Helvetica] mt-1">
                            View · {{ $member->pivot->can_edit ? 'Edit · ' : '' }}{{ $member->pivot->can_sync ? 'Sync · ' : '' }}{{ $member->pivot->can_manage_members ? 'Kelola Anggota' : '' }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('branches.remove-admin', $member) }}" onsubmit="return confirm('Hapus akses user dari cabang ini? Akun tidak akan dihapus.')">
                        @csrf @method('DELETE')
                        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                        <button class="border-2 border-[#c0392b] text-[#c0392b] px-3 py-1 text-xs font-bold">Hapus Akses</button>
                    </form>
                </div>
            @empty
                <div class="px-4 py-6 text-center text-sm">Belum ada anggota cabang.</div>
            @endforelse
        </div>

        <div class="border-2 border-black bg-white">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Tambah / Perbarui Anggota</div>
            <form method="POST" action="{{ route('branches.assign-store', $branch) }}" class="p-4 space-y-4">
                @csrf
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">User</label>
                    <select name="user_id" required class="w-full border-2 border-black px-3 py-2 bg-white">
                        <option value="">— Pilih User —</option>
                        @foreach($availableUsers as $availableUser)
                            <option value="{{ $availableUser->id }}">{{ $availableUser->name }} ({{ $availableUser->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-wrap gap-4 text-sm font-[Helvetica]">
                    <label><input type="checkbox" name="can_edit" value="1"> Edit</label>
                    <label><input type="checkbox" name="can_sync" value="1"> Sync</label>
                    <label><input type="checkbox" name="can_manage_members" value="1"> Kelola Anggota</label>
                </div>
                <button class="bg-black text-white border-2 border-black px-5 py-2 text-sm font-bold">Simpan Membership</button>
            </form>
        </div>
    </div>

    <div class="mt-4"><a href="{{ route('branches.index') }}" class="text-[#0000ee] underline text-sm font-bold">← Kembali ke daftar cabang</a></div>
@endsection
