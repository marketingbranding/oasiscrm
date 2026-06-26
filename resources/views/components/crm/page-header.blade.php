@props(['color' => '#000', 'title' => '', 'textColor' => 'text-black'])
<div class="border-2 border-black px-4 py-2 mb-6" style="background-color: {{ $color }};">
    <h1 class="font-['Arial_Black'] font-black text-xl uppercase {{ $textColor }}">{{ $title }}</h1>
</div>
