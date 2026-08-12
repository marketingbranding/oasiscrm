@extends('layouts.crm')

@section('title', 'Edit Cabang - Oasis CRM')

@section('content')
    <x-crm.page-header color="#8c9ae0" title="Edit Nama Cabang" />

    <x-crm.card>
        <form method="POST" action="{{ route('branches.update', $branch) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <x-crm.field label="Nama Cabang" for="name" required :error="$errors->first('name')">
                <input id="name" name="name" type="text" maxlength="255" required autofocus
                       value="{{ old('name', $branch->name) }}"
                       class="crm-control w-full">
            </x-crm.field>

            <div class="flex flex-wrap gap-3">
                <x-crm.button type="submit" variant="primary">Simpan</x-crm.button>
                <x-crm.button :href="route('branches.index')" variant="secondary">Batal</x-crm.button>
            </div>
        </form>
    </x-crm.card>
@endsection
