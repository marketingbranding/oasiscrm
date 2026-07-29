@props(['title', 'description' => null])

<div {{ $attributes->class(['dashboard-empty-state']) }}>
    <div class="dashboard-empty-state-mark" aria-hidden="true"></div>
    <div>
        <div class="dashboard-empty-state-title">{{ $title }}</div>
        @if($description)
            <p class="dashboard-empty-state-description">{{ $description }}</p>
        @endif
    </div>
</div>
