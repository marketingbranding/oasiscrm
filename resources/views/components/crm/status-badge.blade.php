@props(['variant' => 'neutral'])

@php
    $variant = in_array($variant, ['neutral', 'info', 'pending', 'processing', 'success', 'warning', 'danger', 'inactive', 'archived'], true) ? $variant : 'neutral';
@endphp

<span {{ $attributes->class(["crm-status-badge crm-status-badge--{$variant}"]) }}>{{ $slot }}</span>
