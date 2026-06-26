@props(['color' => '#000', 'textColor' => 'text-black', 'route' => '', 'label' => ''])
<a href="{{ $route }}" class="bg-[color] {{ $textColor }} px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:opacity-90" style="background-color: {{ $color }};">
    {{ $label }}
</a>
