@props(['name'])

<svg {{ $attributes->merge(['class' => 'size-5 shrink-0', 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.7', 'aria-hidden' => 'true']) }}>
    @switch($name)
        @case('dashboard')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12 12 3l9 9M5.5 10.5V21h13V10.5M9.5 21v-6h5v6" />
            @break
        @case('calendar')
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 3v3m11-3v3M4 8.5h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z" />
            @break
        @case('sales')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 20V7l8-4 8 4v13M8 10h8M8 14h8M8 18h5" />
            @break
        @case('database')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3Zm0 0v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" />
            @break
        @case('customers')
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7-1a3 3 0 0 1 0 6m3 5v-1a4 4 0 0 0-2.5-3.7" />
            @break
        @case('operations')
            <path stroke-linecap="round" stroke-linejoin="round" d="m14.5 6.5 3-3 3 3-3 3m-11 5-3 3 3 3 3-3M8 16l8-8" />
            @break
        @case('fund')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18v12H3V6Zm3 3h.01M18 15h.01M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
            @break
        @case('finance')
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21V3Zm3 5h6m-6 4h6m-6 4h4" />
            @break
        @case('category')
            <path stroke-linecap="round" stroke-linejoin="round" d="m3 12 9 9 9-9-9-9H3v9Zm5-4h.01" />
            @break
        @case('reports')
        @case('report')
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3h10l4 4v14H5V3Zm10 0v5h4M8 12h8m-8 4h8" />
            @break
        @case('administration')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0-5v2m0 14v2M3 12h2m14 0h2M5.6 5.6 7 7m10 10 1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4" />
            @break
        @case('branch')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-5.2 7-12a7 7 0 1 0-14 0c0 6.8 7 12 7 12Zm0-9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
            @break
        @case('project')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 21V8l8-5 8 5v13M8 21v-4h8v4M8 10h2m4 0h2m-8 4h2m4 0h2" />
            @break
        @case('users')
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 20v-1.5a4.5 4.5 0 0 0-9 0V20m4.5-8a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-2a3 3 0 0 1 0 6m3.5 4v-1a4 4 0 0 0-2-3.5" />
            @break
        @case('health')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l2-5 4 10 2-5h6M5 4h14v16H5V4Z" />
            @break
        @case('changelog')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v5l3 2m6-3a9 9 0 1 1-3-6.7M18 2v4h4" />
            @break
        @default
            <circle cx="12" cy="12" r="8" />
    @endswitch
</svg>
