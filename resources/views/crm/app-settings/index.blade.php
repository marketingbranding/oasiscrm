@extends('layouts.crm')

@section('title', 'Pengaturan Aturan')

@section('content')
<div class="crm-page">
    <x-crm.page-header
        variant="canonical"
        eyebrow="Administrasi sistem"
        title="Pengaturan Aturan"
        description="Aturan runtime dan feature flag PRD v3. Perubahan berlaku tanpa deploy dan tercatat di log aktivitas."
    />

    <form method="POST" action="{{ route('admin.app-settings.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        @foreach(collect($definitions)->groupBy('group') as $group => $items)
            <x-crm.card variant="emphasis" padding="lg">
                <x-slot:header>
                    <h2 class="font-[Helvetica] text-sm font-bold uppercase tracking-wide">{{ str_replace('_', ' ', $group) }}</h2>
                </x-slot:header>
                <div class="grid gap-5 md:grid-cols-2">
                    @foreach($items as $key => $definition)
                        @php($inputId = 'setting-'.str_replace('.', '-', $key))
                        <x-crm.field
                            :label="$definition['label']"
                            :for="$inputId"
                            :hint="$definition['description'].' Bawaan: '.(is_bool($definition['default']) ? ($definition['default'] ? 'Aktif' : 'Mati') : $definition['default'])"
                            :error="$errors->first($key)"
                        >
                            @if($definition['type'] === 'boolean')
                                <select id="{{ $inputId }}" name="{{ $key }}" class="crm-control">
                                    <option value="0" @selected(! $definition['value'])>Mati</option>
                                    <option value="1" @selected($definition['value'])>Aktif</option>
                                </select>
                            @else
                                <input
                                    id="{{ $inputId }}"
                                    name="{{ $key }}"
                                    type="{{ $definition['type'] === 'integer' ? 'number' : 'text' }}"
                                    value="{{ old($key, $definition['value']) }}"
                                    @if($definition['type'] === 'integer') min="1" @endif
                                    class="crm-control"
                                >
                            @endif
                        </x-crm.field>
                    @endforeach
                </div>
            </x-crm.card>
        @endforeach

        <div class="flex justify-end">
            <x-crm.button type="submit" variant="primary" accent="administration">Simpan pengaturan</x-crm.button>
        </div>
    </form>
</div>
@endsection
