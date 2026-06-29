@props(['field' => '', 'route' => '', 'label' => '', 'currentSort' => null, 'currentDir' => null, 'align' => 'left', 'classes' => ''])
@php
    $currentSort = $currentSort ?? request('sort', '');
    $currentDir = $currentDir ?? request('dir', '');
    $isActive = $currentSort === $field;
    if (!$isActive) {
        $linkParams = array_merge(request()->query(), ['sort' => $field, 'dir' => 'asc']);
        $arrow = '';
    } elseif ($currentDir === 'asc') {
        $linkParams = array_merge(request()->query(), ['sort' => $field, 'dir' => 'desc']);
        $arrow = '▲';
    } else {
        $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
        $arrow = '▼';
    }
    $alignClass = $align === 'center' ? 'text-center' : ($align === 'right' ? 'text-right' : 'text-left');
@endphp
<th class="px-3 py-2 {{ $alignClass }} font-[Helvetica] font-bold text-xs uppercase {{ $classes }}">
    <a href="{{ route($route, $linkParams) }}" class="hover:underline text-white">
        {{ $label }}
        @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
    </a>
</th>
