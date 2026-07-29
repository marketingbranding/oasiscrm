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
    $componentAttributes = $disabled
        ? $attributes->except(['@click', 'x-on:click', 'onclick'])
        : $attributes;
@endphp

@if($href)
    <a @if(!$disabled) href="{{ $href }}" @endif
       aria-label="{{ $label }}" title="{{ $title ?? $label }}"
       @if($disabled) aria-disabled="true" tabindex="-1" @endif
       {{ $componentAttributes->class([$classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" aria-label="{{ $label }}" title="{{ $title ?? $label }}" @disabled($disabled)
            {{ $componentAttributes->class([$classes]) }}>{{ $slot }}</button>
@endif
