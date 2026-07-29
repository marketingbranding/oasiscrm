@props(['label' => null, 'removeHref' => null, 'removeLabel' => null])

<span {{ $attributes->class(['crm-filter-chip']) }}>
    <span>@if($label){{ $label }}@else{{ $slot }}@endif</span>
    @isset($remove)
        {{ $remove }}
    @elseif($removeHref)
        <a href="{{ $removeHref }}" class="crm-filter-chip-remove" aria-label="{{ $removeLabel ?? 'Hapus filter '.$label }}">&times;</a>
    @endisset
</span>
