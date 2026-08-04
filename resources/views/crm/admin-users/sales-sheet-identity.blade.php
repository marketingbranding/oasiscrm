@extends('layouts.crm')
@section('title', 'Identitas Sales PIC - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Identitas Sales PIC" description="Pilih nilai persis dari data_sales untuk {{ $user->name }} di cabang {{ $branch->name }}." />
<x-crm.card>
    <form method="POST" action="{{ route('admin-users.sales-sheet-identity.update', [$user, $branch]) }}" class="space-y-4">@csrf @method('PUT')
        <x-crm.field label="Sales PIC Spreadsheet" for="sales-sheet-value" required :error="$errors->first('spreadsheet_value')">
            <select id="sales-sheet-value" name="spreadsheet_value" class="sales-input" required><option value="">Pilih Sales PIC</option>@foreach($salesOptions as $option)<option value="{{ $option }}" @selected(old('spreadsheet_value', $identity?->spreadsheet_value) === $option)>{{ $option }}</option>@endforeach</select>
        </x-crm.field>
        <div class="flex gap-2"><x-crm.button type="submit" variant="primary">Simpan</x-crm.button><x-crm.button variant="secondary" :href="route('admin-users.edit', $user)">Batal</x-crm.button></div>
    </form>
</x-crm.card>
@endsection
