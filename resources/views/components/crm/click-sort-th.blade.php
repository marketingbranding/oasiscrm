@props([
    'field',
    'route',
    'label',
    'currentSort' => null,
    'currentDir' => null,
    'align' => 'left',
])
@php
    $isActive = $currentSort === $field;
    $nextDir = $isActive && $currentDir === 'asc' ? 'desc' : 'asc';
    $params = array_merge(request()->query(), ['sort' => $field, 'dir' => $nextDir, 'page' => null]);
    $alignClass = $align === 'center' ? 'text-center' : ($align === 'right' ? 'text-right' : 'text-left');
    $icon = $isActive ? ($currentDir === 'asc' ? ' ▼' : ' ▲') : '';
@endphp
<th class="{{ $alignClass }}" style="cursor:pointer;user-select:none;{{ $isActive ? 'background:#5b7db9;' : '' }}">
    <a href="{{ route($route, $params) }}" class="block">{{ $label }}{{ $icon }}</a>
</th>
