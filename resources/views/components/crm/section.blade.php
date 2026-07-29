@props(['id', 'title', 'description' => null, 'eyebrow' => null])

<section id="{{ $id }}" aria-labelledby="{{ $id }}-title" {{ $attributes->class(['crm-section']) }}>
    <header class="crm-section-header">
        <div class="min-w-0">
            @if($eyebrow)<div class="crm-section-eyebrow">{{ $eyebrow }}</div>@endif
            <h2 id="{{ $id }}-title" class="crm-section-title">{{ $title }}</h2>
            @if($description)<p class="crm-section-description">{{ $description }}</p>@endif
        </div>
        @isset($actions)<div class="crm-section-actions">{{ $actions }}</div>@endisset
    </header>
    <div class="crm-section-body">{{ $slot }}</div>
</section>
