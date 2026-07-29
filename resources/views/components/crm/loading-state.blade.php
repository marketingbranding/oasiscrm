@props(['label' => 'Memuat data...', 'compact' => false])

<div role="status" aria-live="polite" {{ $attributes->class(['crm-loading-state', 'crm-loading-state--compact' => $compact]) }}>
    <span class="crm-loading-spinner" aria-hidden="true"></span>
    <span>{{ $label }}</span>
</div>
