@extends('layouts.crm')

@section('title', 'Tambah Data Konsumen - Oasis CRM')

@section('content')
    <x-crm.page-header variant="canonical" eyebrow="Proses Penjualan" title="Tambah Data Konsumen" description="Mulai transaksi baru atau masukkan data lama tanpa membuat riwayat proses yang tidak diketahui.">
        <x-slot:actions><a href="{{ route('consumer-database.workspace') }}" class="crm-button crm-button--secondary">Kembali</a></x-slot:actions>
    </x-crm.page-header>

    <form method="POST" action="{{ route('consumer-database.workspace.store') }}" class="grid gap-4 lg:grid-cols-2">
        @csrf
        <x-crm.card title="Identitas Konsumen" class="lg:col-span-2">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-crm.field label="Nama Konsumen" for="name" required><input id="name" name="name" value="{{ old('name') }}" class="crm-control w-full" required></x-crm.field>
                <x-crm.field label="NIK" for="nik"><input id="nik" name="nik" value="{{ old('nik') }}" inputmode="numeric" maxlength="16" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="No. HP" for="phone"><input id="phone" name="phone" value="{{ old('phone') }}" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="Tanggal Lahir" for="date_of_birth"><input id="date_of_birth" type="date" name="date_of_birth" value="{{ old('date_of_birth') }}" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="Pekerjaan" for="occupation"><input id="occupation" name="occupation" value="{{ old('occupation') }}" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="Detail Pekerjaan" for="occupation_detail"><input id="occupation_detail" name="occupation_detail" value="{{ old('occupation_detail') }}" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="Alamat" for="address" class="sm:col-span-2 lg:col-span-3"><textarea id="address" name="address" class="crm-control min-h-20 w-full">{{ old('address') }}</textarea></x-crm.field>
                <x-crm.field label="Kelurahan" for="kelurahan"><input id="kelurahan" name="kelurahan" value="{{ old('kelurahan') }}" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="Kecamatan" for="kecamatan"><input id="kecamatan" name="kecamatan" value="{{ old('kecamatan') }}" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="Kabupaten/Kota" for="kabupaten_kota"><input id="kabupaten_kota" name="kabupaten_kota" value="{{ old('kabupaten_kota') }}" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="Nama Kontak Darurat" for="emergency_contact_name"><input id="emergency_contact_name" name="emergency_contact_name" value="{{ old('emergency_contact_name') }}" class="crm-control w-full"></x-crm.field>
                <x-crm.field label="No. HP Kontak Darurat" for="emergency_contact_phone"><input id="emergency_contact_phone" name="emergency_contact_phone" value="{{ old('emergency_contact_phone') }}" class="crm-control w-full"></x-crm.field>
            </div>
        </x-crm.card>

        <x-crm.card title="Konteks Transaksi">
            <div class="grid gap-3">
                <x-crm.field label="Cabang" for="branch_id" required><select id="branch_id" name="branch_id" class="crm-control w-full" required><option value="">Pilih cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Proyek" for="project_id" required><select id="project_id" name="project_id" class="crm-control w-full" required><option value="">Pilih proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(old('project_id') == $project->id)>{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Kavling" for="kavling_id"><input id="kavling_id" name="kavling_id" value="{{ old('kavling_id') }}" type="number" class="crm-control w-full"><small class="text-xs text-gray-600">Kosongkan untuk data lama yang belum diketahui.</small></x-crm.field>
                <x-crm.field label="Cara Pembayaran" for="payment_method"><select id="payment_method" name="payment_method" class="crm-control w-full"><option value="">Belum ditentukan</option><option value="kpr" @selected(old('payment_method') === 'kpr')>KPR</option><option value="cash" @selected(old('payment_method') === 'cash')>Cash</option><option value="cash_bertahap" @selected(old('payment_method') === 'cash_bertahap')>Cash Bertahap</option></select></x-crm.field>
            </div>
        </x-crm.card>

        <x-crm.card title="Posisi Proses">
            <div class="grid gap-3">
                <x-crm.field label="Jenis Data" for="entry_mode" required><select id="entry_mode" name="entry_mode" class="crm-control w-full"><option value="new">Konsumen Baru</option><option value="historical" @selected(old('entry_mode') === 'historical')>Data Lama / Migrasi</option></select></x-crm.field>
                <x-crm.field label="Posisi Konsumen Saat Ini" for="current_process"><select id="current_process" name="current_process" class="crm-control w-full"><option value="data_konsumen">Data Konsumen</option>@foreach(['psjb' => 'PSJB', 'slik' => 'SLIK', 'pemberkasan' => 'Pemberkasan', 'proses_bank' => 'Proses Bank', 'sp3k' => 'SP3K', 'ppjb' => 'PPJB', 'akad' => 'Akad', 'bast' => 'BAST', 'garansi' => 'Form Garansi', 'selesai' => 'Selesai'] as $value => $label)<option value="{{ $value }}" @selected(old('current_process') === $value)>{{ $label }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Keterangan" for="notes"><textarea id="notes" name="notes" class="crm-control min-h-24 w-full">{{ old('notes') }}</textarea></x-crm.field>
            </div>
        </x-crm.card>

        <div class="flex flex-wrap gap-2 lg:col-span-2"><button type="submit" class="crm-button crm-button--primary">Simpan Data Konsumen</button><a href="{{ route('consumer-database.workspace') }}" class="crm-button crm-button--secondary">Batal</a></div>
    </form>
@endsection
