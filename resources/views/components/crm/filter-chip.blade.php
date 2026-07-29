@props(['label', 'removeHref' => null, 'removeLabel' => null])

<span {{ $attributes->class(['crm-filter-chip']) }}>
    <span>{{ $label }}</span>
    @if($removeHref)
        <a href="{{ $removeHref }}" class="crm-filter-chip-remove" aria-label="{{ $removeLabel ?? 'Hapus filter '.$label }}">&times;</a>
    @endif
</span>
