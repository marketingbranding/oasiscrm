@php
    $typeColors = ['task' => '#9ab6c8', 'agenda' => '#e6915d', 'content' => '#8c9ae0'];
    $typeLabels = ['task' => 'TASK', 'agenda' => 'AGENDA', 'content' => 'KONTEN'];
    $statusLabels = ['idea' => 'Ide', 'content_in_progress' => 'Dalam Proses', 'done_editing' => 'Selesai Edit', 'uploaded' => 'Di Upload', 'rescheduled' => 'Dijadwalkan Ulang'];
    $statusVariant = match ($plannerItem->status) {
        'completed', 'done', 'uploaded' => 'success',
        'in_progress', 'confirmed', 'content_in_progress', 'done_editing' => 'processing',
        'lost_track', 'cancelled' => 'danger',
        'rescheduled' => 'warning',
        default => 'pending',
    };
    $isOverdue = $plannerItem->item_type === 'task'
        && $plannerItem->deadline_date
        && $plannerItem->deadline_date->isPast()
        && $plannerItem->status !== 'completed';
@endphp
<article class="planner-board-card border-2 border-black bg-white p-3 shadow-[2px_2px_0_#000] hover:bg-[#fef3c7]" data-item-id="{{ $plannerItem->id }}" data-item-type="{{ $plannerItem->item_type }}" data-updated-at="{{ $plannerItem->updated_at?->copy()->utc()->format('Y-m-d H:i:s') }}">
    <div class="flex items-start gap-2">
        @if($canUpdatePlanner ?? auth()->user()->hasPermission('work_planner.update'))
        <input type="checkbox" class="mt-1 shrink-0" aria-label="Pilih {{ $plannerItem->title }}" :checked="isSelected({{ $plannerItem->id }})" @click.stop="toggle({{ $plannerItem->id }})">
        @endif
        <button type="button" @click="openDetail({{ $plannerItem->id }})" class="min-w-0 grow text-left">
            <div class="flex items-center justify-between gap-2">
                <span class="border border-black px-1.5 py-0.5 text-[9px] font-[Helvetica] font-bold" style="background:{{ $typeColors[$plannerItem->item_type] ?? '#ccc' }}">{{ $typeLabels[$plannerItem->item_type] ?? strtoupper($plannerItem->item_type) }}</span>
                <x-crm.status-badge :variant="$statusVariant">{{ $statusLabels[$plannerItem->status] ?? str_replace('_', ' ', $plannerItem->status) }}</x-crm.status-badge>
            </div>
            <strong class="block mt-1 truncate font-[Helvetica] text-xs" title="{{ $plannerItem->title }}">{{ $plannerItem->title }}</strong>
            <span class="block truncate text-[11px] font-['Times_New_Roman']">{{ $plannerItem->item_type === 'content' ? ($plannerItem->tujuan_konten ?: 'Tanpa tujuan') : ($plannerItem->project_name ?: 'Tanpa proyek') }}</span>
            <span class="block mt-1 text-[10px] font-['Times_New_Roman']">
                {{ $plannerItem->scheduled_date?->format('d M Y') ?? ($plannerItem->item_type === 'content' ? ($plannerItem->platform ?: '—') : '—') }}
                @if($plannerItem->start_time) · {{ substr($plannerItem->start_time, 0, 5) }} @endif
                @if($plannerItem->location) · {{ $plannerItem->location }} @endif
                @if($plannerItem->item_type === 'content' && $plannerItem->content_format) · {{ $plannerItem->content_format }} @endif
            </span>
            @if($plannerItem->assignees->isNotEmpty() || !empty($plannerItem->pic_names))
            <span class="block mt-1 truncate text-[10px] text-gray-600">PIC: {{ $plannerItem->assignees->pluck('name')->merge($plannerItem->pic_names ?? [])->join(', ') }}</span>
            @endif
            @if($isOverdue)
            <span class="mt-2 inline-flex border border-[#c0392b] bg-red-50 px-2 py-0.5 text-[10px] font-bold text-[#c0392b]">Terlambat dan perlu tindakan</span>
            @endif
        </button>
    </div>
    @if(auth()->user()->hasPermission('comments.view'))
    <a href="{{ route('comments.thread', ['alias' => 'planner-item', 'id' => $plannerItem->id]) }}" class="ml-6 mt-1 inline-block font-[Helvetica] text-[10px] font-bold text-[#0000ee] underline">Komentar ({{ $plannerItem->comments_count }})</a>
    @endif
</article>
