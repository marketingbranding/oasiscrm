@php
    $typeColors = ['task' => '#9ab6c8', 'agenda' => '#e6915d', 'content' => '#8c9ae0'];
    $typeLabels = ['task' => 'TASK', 'agenda' => 'AGENDA', 'content' => 'KONTEN'];
@endphp
<article class="border-2 border-black bg-white p-2 shadow-[2px_2px_0_#000] hover:bg-[#fef3c7]">
    <div class="flex items-start gap-2">
        <input type="checkbox" class="mt-1 shrink-0" :checked="isSelected({{ $plannerItem->id }})" @click.stop="toggle({{ $plannerItem->id }})">
        <button type="button" @click="openDetail({{ $plannerItem->id }})" class="min-w-0 grow text-left">
            <div class="flex items-center justify-between gap-2">
                <span class="border border-black px-1.5 py-0.5 text-[9px] font-[Helvetica] font-bold" style="background:{{ $typeColors[$plannerItem->item_type] ?? '#ccc' }}">{{ $typeLabels[$plannerItem->item_type] ?? strtoupper($plannerItem->item_type) }}</span>
                <span class="text-[9px] font-[Helvetica] font-bold uppercase">{{ str_replace('_', ' ', $plannerItem->status) }}</span>
            </div>
            <strong class="block mt-1 truncate font-[Helvetica] text-xs" title="{{ $plannerItem->title }}">{{ $plannerItem->title }}</strong>
            <span class="block truncate text-[11px] font-['Times_New_Roman']">{{ $plannerItem->project_name ?: 'Tanpa proyek' }}</span>
            <span class="block mt-1 text-[10px] font-['Times_New_Roman']">
                {{ $plannerItem->scheduled_date->format('d M Y') }}
                @if($plannerItem->start_time) · {{ substr($plannerItem->start_time, 0, 5) }} @endif
                @if($plannerItem->location) · {{ $plannerItem->location }} @endif
            </span>
            @if($plannerItem->assignees->isNotEmpty() || !empty($plannerItem->pic_names))
            <span class="block mt-1 truncate text-[10px] text-gray-600">PIC: {{ $plannerItem->assignees->pluck('name')->merge($plannerItem->pic_names ?? [])->join(', ') }}</span>
            @endif
        </button>
    </div>
</article>
