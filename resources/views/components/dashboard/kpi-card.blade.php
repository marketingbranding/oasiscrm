@props(['label', 'value', 'context' => null, 'accent' => 'neutral'])

<article class="dashboard-kpi" data-accent="{{ $accent }}">
    <div class="dashboard-kpi-label">{{ $label }}</div>
    <div class="dashboard-kpi-value" title="{{ strip_tags((string) $value) }}">{{ $value }}</div>
    @if($context)
        <div class="dashboard-kpi-context">{{ $context }}</div>
    @endif
</article>
