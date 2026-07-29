@props([
    'name',
    'title',
    'description' => null,
    'size' => 'md',
    'initiallyOpen' => false,
])

@php
    $size = in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
    $dialogId = 'crm-modal-'.Str::slug($name);
@endphp

<div x-data="crmModal(@js($name), @js((bool) $initiallyOpen))"
     @oasis:modal-open.window="show($event)"
     @oasis:modal-close.window="closeFromEvent($event)"
     x-show="open" x-cloak
     class="crm-modal-backdrop"
     @click.self="hide(true, 'backdrop')"
     @keydown="handleKeydown($event)">
    <section x-ref="panel" role="dialog" aria-modal="true" tabindex="-1"
             aria-labelledby="{{ $dialogId }}-title"
             @if($description) aria-describedby="{{ $dialogId }}-description" @endif
             class="crm-modal-panel crm-modal-panel--{{ $size }}">
        <header class="crm-modal-header">
            <div class="min-w-0">
                <h2 id="{{ $dialogId }}-title" class="crm-modal-title">{{ $title }}</h2>
                @if($description)<p id="{{ $dialogId }}-description" class="crm-modal-description">{{ $description }}</p>@endif
            </div>
            <x-crm.icon-button label="Tutup dialog" variant="ghost" x-ref="close" @click="hide(true, 'close-button')">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
            </x-crm.icon-button>
        </header>
        <div class="crm-modal-body">{{ $slot }}</div>
        @isset($footer)<footer class="crm-modal-footer">{{ $footer }}</footer>@endisset
    </section>
</div>
