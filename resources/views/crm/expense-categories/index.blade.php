@extends('layouts.crm')

@section('title', 'Kategori Pengeluaran - Oasis CRM')

@section('content')
    <x-crm.page-header
        variant="canonical"
        title="Kategori Pengeluaran"
        eyebrow="Administrasi Keuangan"
        description="Kelola nama, urutan, dan status kategori tanpa mengubah kode tetap untuk riwayat data."
    >
        <x-slot:actions>
            <x-crm.button href="{{ route('expenses.index') }}" variant="secondary">Kembali ke Pengeluaran</x-crm.button>
        </x-slot:actions>
    </x-crm.page-header>

    <form method="POST" action="{{ route('expense-categories.store') }}" class="mb-4 grid gap-3 border-2 border-black bg-white p-4 md:grid-cols-[1fr_10rem_auto] md:items-end">
        @csrf
        <div>
            <label for="name" class="mb-1 block text-xs font-bold uppercase">Nama kategori</label>
            <input id="name" name="name" value="{{ old('name') }}" required maxlength="255" class="crm-control">
            @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            @error('code') <p class="mt-1 text-sm text-red-700">Nama tersebut menghasilkan kode yang sudah digunakan.</p> @enderror
        </div>
        <div>
            <label for="sort_order" class="mb-1 block text-xs font-bold uppercase">Urutan</label>
            <input id="sort_order" type="number" min="0" name="sort_order" value="{{ old('sort_order', 0) }}" required class="crm-control">
            @error('sort_order') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <x-crm.button type="submit" variant="primary" accent="expenses">Tambah</x-crm.button>
    </form>

    <div class="crm-table-scroll">
        <table class="crm-data-table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Kode tetap</th>
                    <th>Urutan</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $category)
                    <tr>
                        <td><input form="update-category-{{ $category->id }}" name="name" value="{{ $category->name }}" required maxlength="255" class="w-full min-w-48 border border-black px-2 py-1"></td>
                        <td class="font-mono" title="Kode tidak berubah saat nama diperbarui">{{ $category->code }}</td>
                        <td><input form="update-category-{{ $category->id }}" type="number" min="0" name="sort_order" value="{{ $category->sort_order }}" required class="w-20 border border-black px-2 py-1"></td>
                        <td>
                            <form method="POST" action="{{ route('expense-categories.toggle', $category) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-left" aria-label="{{ $category->is_active ? 'Nonaktifkan' : 'Aktifkan' }} kategori {{ $category->name }}">
                                    <x-crm.status-badge :variant="$category->is_active ? 'success' : 'inactive'">{{ $category->is_active ? 'AKTIF' : 'NONAKTIF' }}</x-crm.status-badge>
                                </button>
                            </form>
                        </td>
                        <td>
                            <form id="update-category-{{ $category->id }}" method="POST" action="{{ route('expense-categories.update', $category) }}">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="font-bold text-[#0000ee] underline">Simpan</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-crm.empty-state title="Belum ada kategori pengeluaran." description="Tambahkan kategori pertama agar pengeluaran dapat dicatat." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
