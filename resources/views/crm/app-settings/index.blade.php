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
                        @php($settingKey = $definition['key'])
                        @php($inputId = 'setting-'.str_replace('.', '-', $settingKey))
                        @php($inputKey = str_replace('.', '__', $settingKey))
                        @php($oldKey = 'settings.'.$inputKey)
                        <x-crm.field
                            :label="$definition['label']"
                            :for="$inputId"
                            :hint="$definition['description'].' Bawaan: '.(is_bool($definition['default']) ? ($definition['default'] ? 'Aktif' : 'Mati') : ($definition['type'] === 'scoped_feature_flag' ? 'Mati' : (is_array($definition['default']) ? implode(', ', $definition['default']) : $definition['default'])))"
                            :error="$errors->first($oldKey)"
                        >
                            @if($definition['type'] === 'scoped_feature_flag')
                                <select id="{{ $inputId }}-mode" name="settings[{{ $inputKey }}__mode]" class="crm-control">
                                    @foreach(['off' => 'Mati', 'branches' => 'Cabang tertentu', 'all' => 'Semua cabang'] as $mode => $label)
                                        <option value="{{ $mode }}" @selected(($definition['value']['mode'] ?? 'off') === $mode)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <label class="mt-2 block text-xs font-bold" for="{{ $inputId }}-branches">Cabang rollout</label>
                                <select id="{{ $inputId }}-branches" name="settings[{{ $inputKey }}__branch_ids][]" class="crm-control" multiple>
                                    @foreach(\App\Models\Branch::query()->where('is_active', true)->orderBy('name')->get() as $branch)
                                        <option value="{{ $branch->id }}" @selected(in_array($branch->id, $definition['value']['branch_ids'] ?? [], true))>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            @elseif($definition['type'] === 'boolean')
                                <select id="{{ $inputId }}" name="settings[{{ $inputKey }}]" class="crm-control">
                                    <option value="0" @selected(old($oldKey, $definition['value']) === false || old($oldKey, $definition['value']) === '0')>Mati</option>
                                    <option value="1" @selected(old($oldKey, $definition['value']) === true || old($oldKey, $definition['value']) === '1')>Aktif</option>
                                </select>
                            @elseif($definition['type'] === 'list')
                                <textarea id="{{ $inputId }}" name="settings[{{ $inputKey }}]" rows="5" class="crm-control">{{ old($oldKey, implode("\n", $definition['value'])) }}</textarea>
                            @else
                                <input
                                    id="{{ $inputId }}"
                                    name="settings[{{ $inputKey }}]"
                                    type="{{ $definition['type'] === 'integer' ? 'number' : 'text' }}"
                                    value="{{ old($oldKey, $definition['value']) }}"
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
