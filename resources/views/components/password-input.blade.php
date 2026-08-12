@props([
    'disabled' => false,
    'showLabel' => 'Tampilkan password',
    'hideLabel' => 'Sembunyikan password',
])

<div x-data="{ visible: false }" class="relative">
    <input
        @disabled($disabled)
        type="password"
        :type="visible ? 'text' : 'password'"
        {{ $attributes->merge(['class' => 'pr-12']) }}
    >
    <button
        type="button"
        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-black focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-4px] focus-visible:outline-black"
        @click="visible = ! visible"
        :aria-label="visible ? @js($hideLabel) : @js($showLabel)"
        :title="visible ? @js($hideLabel) : @js($showLabel)"
        :aria-pressed="visible.toString()"
    >
        <svg x-show="! visible" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true">
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
        <svg x-cloak x-show="visible" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true">
            <path d="m3 3 18 18" />
            <path d="M10.6 5.1A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a17.7 17.7 0 0 1-2.1 3.2M6.6 6.6C3.6 8.5 2 12 2 12s3.5 7 10 7a9.8 9.8 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2" />
        </svg>
    </button>
</div>
