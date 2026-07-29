@props([
    'variant' => 'secondary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'loading' => false,
    'accent' => null,
])

@php
    $variant = in_array($variant, ['primary', 'secondary', 'ghost', 'text', 'danger'], true) ? $variant : 'secondary';
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
    $accent = in_array($accent, ['dashboard', 'planner', 'sales', 'database', 'consumer-progress', 'bridge-fund', 'expenses', 'reports', 'administration'], true) ? $accent : null;
    $isDisabled = (bool) $disabled || (bool) $loading;
    $classes = "crm-button crm-button--{$variant} crm-button--{$size}";
@endphp

@if($href)
    <a @if(!$isDisabled) href="{{ $href }}" @endif
       @if($isDisabled) aria-disabled="true" tabindex="-1" @endif
       @if($loading) aria-busy="true" @endif
       @if($accent) data-accent="{{ $accent }}" @endif
       {{ $attributes->class([$classes]) }}>
        @if($loading)<span class="crm-button-spinner" aria-hidden="true"></span>@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" @disabled($isDisabled)
            @if($loading) aria-busy="true" @endif
            @if($accent) data-accent="{{ $accent }}" @endif
            {{ $attributes->class([$classes]) }}>
        @if($loading)<span class="crm-button-spinner" aria-hidden="true"></span>@endif
        {{ $slot }}
    </button>
@endif
