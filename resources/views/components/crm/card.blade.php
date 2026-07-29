@props(['variant' => 'default', 'padding' => 'md'])

@php
    $variant = in_array($variant, ['default', 'muted', 'raised', 'emphasis'], true) ? $variant : 'default';
    $padding = in_array($padding, ['none', 'sm', 'md', 'lg'], true) ? $padding : 'md';
@endphp

<div {{ $attributes->class(["crm-card crm-card--{$variant} crm-card--padding-{$padding}"]) }}>
    @isset($header)<div class="crm-card-header">{{ $header }}</div>@endisset
    <div class="crm-card-body">{{ $slot }}</div>
    @isset($footer)<div class="crm-card-footer">{{ $footer }}</div>@endisset
</div>
