@extends('layouts.crm')

@section('title', 'Hari Libur')

@section('content')
<div class="crm-page">
    <x-crm.page-header
        variant="canonical"
        eyebrow="Pengaturan aturan"
        title="Hari Libur"
        description="Kelola hari libur nasional, cuti bersama, dan libur khusus cabang."
    />

    <x-crm.card variant="emphasis" padding="lg">
        <x-slot:header><h2 class="font-[Helvetica] text-sm font-bold uppercase tracking-wide">Tambah hari libur</h2></x-slot:header>
        <form method="POST" action="{{ route('admin.holidays.store') }}" class="grid gap-5 md:grid-cols-3">
            @csrf
            <x-crm.field label="Tanggal" for="holiday-date" :error="$errors->first('date')">
                <input id="holiday-date" name="date" type="date" value="{{ old('date') }}" class="crm-control" required>
            </x-crm.field>
            <x-crm.field label="Nama" for="holiday-name" :error="$errors->first('name')">
                <input id="holiday-name" name="name" type="text" value="{{ old('name') }}" class="crm-control" required>
            </x-crm.field>
            <x-crm.field label="Cabang (opsional)" for="holiday-branch" :error="$errors->first('branch_id')">
                <select id="holiday-branch" name="branch_id" class="crm-control">
                    <option value="">Semua cabang</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </x-crm.field>
            <div class="md:col-span-3 flex justify-end">
                <x-crm.button type="submit" variant="primary" accent="administration">Tambah hari libur</x-crm.button>
            </div>
        </form>
    </x-crm.card>

    <x-crm.card padding="lg">
        <div class="crm-table-scroll">
            <table class="crm-data-table">
                <thead><tr><th>Tanggal</th><th>Nama</th><th>Cakupan</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse($holidays as $holiday)
                        <tr>
                            <td>{{ $holiday->date->format('d/m/Y') }}</td>
                            <td>{{ $holiday->name }}</td>
                            <td>{{ $holiday->branch?->name ?? 'Semua cabang' }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.holidays.destroy', $holiday) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-crm.button type="submit" variant="danger" accent="administration">Hapus</x-crm.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Belum ada hari libur.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-crm.card>
</div>
@endsection
