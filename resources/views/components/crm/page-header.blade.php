@props([
    'color' => '#000',
    'title' => '',
    'textColor' => 'text-black',
    'variant' => 'legacy',
    'description' => null,
    'eyebrow' => null,
])

@if($variant === 'canonical')
    <header {{ $attributes->class(['crm-page-header']) }}>
        <div class="min-w-0">
            @if($eyebrow)<div class="crm-page-header-eyebrow">{{ $eyebrow }}</div>@endif
            <h1 class="crm-page-header-title">{{ $title }}</h1>
            @if($description)<p class="crm-page-header-description">{{ $description }}</p>@endif
        </div>
        @isset($actions)<div class="crm-page-header-actions">{{ $actions }}</div>@endisset
    </header>
@else
    <div {{ $attributes->class(['border-2 border-black px-4 py-2 mb-6']) }} style="background-color: {{ $color }};">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase {{ $textColor }}">{{ $title }}</h1>
    </div>
@endif
