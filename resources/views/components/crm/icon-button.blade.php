@props([
    'label',
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'title' => null,
])

@php
    $variant = in_array($variant, ['secondary', 'ghost', 'danger'], true) ? $variant : 'secondary';
    $classes = "crm-icon-button crm-icon-button--{$variant}";
@endphp

@if($href)
    <a @if(!$disabled) href="{{ $href }}" @endif
       aria-label="{{ $label }}" title="{{ $title ?? $label }}"
       @if($disabled) aria-disabled="true" tabindex="-1" @endif
       {{ $attributes->class([$classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" aria-label="{{ $label }}" title="{{ $title ?? $label }}" @disabled($disabled)
            {{ $attributes->class([$classes]) }}>{{ $slot }}</button>
@endif
