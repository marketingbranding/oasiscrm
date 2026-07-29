@props(['id', 'eyebrow' => null, 'title', 'description' => null])

<section id="{{ $id }}" {{ $attributes->class(['dashboard-section']) }}>
    <header class="dashboard-section-header">
        <div class="min-w-0">
            @if($eyebrow)
                <div class="dashboard-section-eyebrow">{{ $eyebrow }}</div>
            @endif
            <h2 class="dashboard-section-title">{{ $title }}</h2>
            @if($description)
                <p class="dashboard-section-description">{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="dashboard-section-actions">{{ $actions }}</div>
        @endisset
    </header>
    <div class="dashboard-section-body">{{ $slot }}</div>
</section>
