@props(['field' => '', 'route' => '', 'label' => '', 'currentSort' => null, 'currentDir' => null, 'align' => 'left', 'classes' => '', 'type' => 'text'])
@php
    $currentSort = $currentSort ?? request('sort', '');
    $currentDir = $currentDir ?? request('dir', '');
    $isActive = $currentSort === $field;
    $ascParams = array_merge(request()->query(), ['sort' => $field, 'dir' => 'asc']);
    $descParams = array_merge(request()->query(), ['sort' => $field, 'dir' => 'desc']);
    $firstParams = $type === 'date' ? $descParams : $ascParams;
    $secondParams = $type === 'date' ? $ascParams : $descParams;
    $firstDir = $type === 'date' ? 'desc' : 'asc';
    $secondDir = $type === 'date' ? 'asc' : 'desc';
    $firstLabel = $type === 'date' ? 'Newest' : 'A-Z';
    $secondLabel = $type === 'date' ? 'Oldest' : 'Z-A';
    $alignClass = $align === 'center' ? 'text-center' : ($align === 'right' ? 'text-right' : 'text-left');
    $menuButtonBase = 'block w-full px-3 py-1.5 text-left text-[10px] font-[Helvetica] font-bold uppercase hover:bg-black hover:text-white';
@endphp
<th class="px-3 py-2 {{ $alignClass }} font-[Helvetica] font-bold text-xs uppercase {{ $classes }}" x-data="{ open: false }">
    <div class="relative inline-flex items-center gap-1 {{ $align === 'center' ? 'justify-center' : ($align === 'right' ? 'justify-end' : 'justify-start') }}">
        <span>{{ $label }}</span>
        <button type="button"
                @click="open = !open"
                @keydown.escape.window="open = false"
                class="inline-flex h-5 w-5 items-center justify-center border border-white text-white hover:bg-white hover:text-black {{ $isActive ? 'bg-white text-black' : '' }}"
                aria-label="Sort {{ $label }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
            </svg>
        </button>
        <div x-cloak
             x-show="open"
             @click.outside="open = false"
             x-transition.opacity.duration.100ms
             class="absolute top-full z-30 mt-1 min-w-28 border-2 border-black bg-white text-black shadow-[4px_4px_0_0_#000] {{ $align === 'right' ? 'right-0' : 'left-0' }}">
            <a href="{{ route($route, $firstParams) }}"
               class="{{ $menuButtonBase }} {{ $isActive && $currentDir === $firstDir ? 'bg-black text-white' : 'text-black' }}">
                {{ $firstLabel }}
            </a>
            <a href="{{ route($route, $secondParams) }}"
               class="{{ $menuButtonBase }} {{ $isActive && $currentDir === $secondDir ? 'bg-black text-white' : 'text-black' }}">
                {{ $secondLabel }}
            </a>
        </div>
    </div>
</th>
