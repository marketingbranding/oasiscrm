@extends('layouts.crm')

@section('title', 'Kategori Pengeluaran - Oasis CRM')

@section('content')
    <div class="mb-6 border-2 border-black bg-[#5d8e8e] px-4 py-2">
        <h1 class="font-['Arial_Black'] text-xl font-black uppercase">Kategori Pengeluaran</h1>
    </div>

    <form method="POST" action="{{ route('expense-categories.store') }}" class="mb-6 grid gap-3 border-2 border-black bg-white p-4 md:grid-cols-[1fr_10rem_auto] md:items-end">
        @csrf
        <div>
            <label for="name" class="mb-1 block text-xs font-bold uppercase">Nama kategori</label>
            <input id="name" name="name" value="{{ old('name') }}" required maxlength="255" class="w-full border-2 border-black px-3 py-2">
            @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            @error('code') <p class="mt-1 text-sm text-red-700">Nama tersebut menghasilkan kode yang sudah digunakan.</p> @enderror
        </div>
        <div>
            <label for="sort_order" class="mb-1 block text-xs font-bold uppercase">Urutan</label>
            <input id="sort_order" type="number" min="0" name="sort_order" value="{{ old('sort_order', 0) }}" required class="w-full border-2 border-black px-3 py-2">
            @error('sort_order') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="border-2 border-black bg-[#b3bd95] px-4 py-2 font-bold hover:bg-[#9eaa7a]">Tambah</button>
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
                                <button type="submit" class="border border-black px-2 py-0.5 text-xs font-bold {{ $category->is_active ? 'bg-[#b3bd95]' : 'bg-gray-200' }}">
                                    {{ $category->is_active ? 'AKTIF' : 'NONAKTIF' }}
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
                    <tr><td colspan="5" class="py-8 text-center">Belum ada kategori pengeluaran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
