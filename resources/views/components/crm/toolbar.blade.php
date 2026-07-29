@props(['label' => 'Alat daftar'])

<div role="group" aria-label="{{ $label }}" {{ $attributes->class(['crm-toolbar']) }}>
    <div class="crm-toolbar-content">{{ $slot }}</div>
    @isset($actions)<div class="crm-toolbar-actions">{{ $actions }}</div>@endisset
</div>
