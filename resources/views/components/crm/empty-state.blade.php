@props(['title', 'description' => null])

<div {{ $attributes->class(['crm-empty-state']) }}>
    <span class="crm-empty-state-mark" aria-hidden="true"></span>
    <div class="min-w-0">
        <div class="crm-empty-state-title">{{ $title }}</div>
        @if($description)<p class="crm-empty-state-description">{{ $description }}</p>@endif
        @isset($actions)<div class="crm-empty-state-actions">{{ $actions }}</div>@endisset
    </div>
</div>
