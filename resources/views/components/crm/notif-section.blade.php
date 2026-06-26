@props(['items' => [], 'color' => '#000', 'label' => '', 'textColor' => 'text-white'])
@if($items->count() > 0)
<div class="bg-[{{ $color }}] {{ $textColor }} px-3 py-1 font-[Helvetica] font-bold text-[10px] uppercase border-t-2 border-black">{{ $label }}</div>
<div class="divide-y divide-black">
    {{ $slot }}
</div>
@endif
