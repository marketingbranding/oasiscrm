@props([
    'label',
    'for',
    'required' => false,
    'hint' => null,
    'error' => null,
])

<div {{ $attributes->class(['crm-field', 'crm-field--invalid' => (bool) $error]) }}>
    <label for="{{ $for }}" class="crm-field-label">
        <span>{{ $label }}</span>
        @if($required)<span class="crm-field-required" aria-hidden="true">*</span><span class="sr-only"> wajib</span>@endif
    </label>
    <div class="crm-field-control">{{ $slot }}</div>
    @if($hint)<p id="{{ $for }}-hint" class="crm-field-hint">{{ $hint }}</p>@endif
    <x-crm.input-error :id="$for.'-error'" :message="$error" />
</div>
