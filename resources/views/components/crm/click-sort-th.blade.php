@props([
    'field',
    'route',
    'label',
    'currentSort' => null,
    'currentDir' => null,
    'align' => 'left',
    'directionParam' => 'dir',
    'resetPageKeys' => ['page'],
    'currentIndicator' => false,
])
@php
    $isActive = $currentSort === $field;
    $nextDir = $isActive && $currentDir === 'asc' ? 'desc' : 'asc';
    $params = array_merge(request()->query(), ['sort' => $field, $directionParam => $nextDir]);
    foreach ($resetPageKeys as $pageKey) {
        $params[$pageKey] = null;
    }
    $alignClass = $align === 'center' ? 'text-center' : ($align === 'right' ? 'text-right' : 'text-left');
    $icon = $isActive
        ? ($currentIndicator
            ? ($currentDir === 'asc' ? ' ▲' : ' ▼')
            : ($currentDir === 'asc' ? ' ▼' : ' ▲'))
        : '';
@endphp
<th scope="col" class="{{ $alignClass }}" aria-sort="{{ $isActive ? ($currentDir === 'asc' ? 'ascending' : 'descending') : 'none' }}" style="cursor:pointer;user-select:none;{{ $isActive ? 'background:#5b7db9;' : '' }}">
    <a href="{{ route($route, $params) }}" class="block" aria-label="Urutkan berdasarkan {{ $label }}, {{ $nextDir === 'asc' ? 'menaik' : 'menurun' }}">
        {{ $label }}<span aria-hidden="true">{{ $icon }}</span>
    </a>
</th>
