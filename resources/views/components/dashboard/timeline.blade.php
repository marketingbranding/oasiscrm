@props(['items'])

@if($items->isEmpty())
    <x-crm.empty-state title="Belum ada aktivitas operasional" description="Aktivitas baru pada area kerja ini akan muncul di sini." />
@else
    <ol class="dashboard-timeline" aria-label="Aktivitas operasional terbaru">
        @foreach($items as $item)
            <li class="dashboard-timeline-item" data-type="{{ Str::slug($item['type']) }}">
                <time datetime="{{ $item['time']->toIso8601String() }}" class="dashboard-timeline-time">{{ $item['time']->format('H:i') }}</time>
                <div class="dashboard-timeline-marker" aria-hidden="true"></div>
                <div class="min-w-0 pb-4">
                    <div class="dashboard-timeline-meta">
                        <span>{{ $item['type'] }}</span>
                        <span aria-hidden="true">/</span>
                        <span>{{ $item['user'] }}</span>
                    </div>
                    <div class="dashboard-timeline-text">{{ $item['text'] }}</div>
                    <div class="dashboard-timeline-relative">{{ $item['time']->diffForHumans() }}</div>
                </div>
            </li>
        @endforeach
    </ol>
@endif
