@props(['variant' => 'info', 'title' => null, 'role' => null])

@php
    $variant = in_array($variant, ['info', 'success', 'warning', 'error'], true) ? $variant : 'info';
    $resolvedRole = $role ?? ($variant === 'error' ? 'alert' : 'status');
@endphp

<div role="{{ $resolvedRole }}" {{ $attributes->class(["crm-alert crm-alert--{$variant}"]) }}>
    <span class="crm-alert-mark" aria-hidden="true"></span>
    <div class="min-w-0 flex-1">
        @if($title)<div class="crm-alert-title">{{ $title }}</div>@endif
        <div class="crm-alert-body">{{ $slot }}</div>
    </div>
    @isset($actions)<div class="crm-alert-actions">{{ $actions }}</div>@endisset
</div>
