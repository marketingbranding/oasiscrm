<div class="workspace-v2-page-header">
    <div>
        <div class="workspace-v2-eyebrow">{{ $eyebrow ?? 'WORKSPACE' }}</div>
        <h1>{{ $title }}</h1>
        @isset($description)<p>{{ $description }}</p>@endisset
    </div>
    @isset($action)<div class="workspace-v2-page-action">{!! $action !!}</div>@endisset
</div>
