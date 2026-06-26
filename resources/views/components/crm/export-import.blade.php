@props(['exportRoute' => '', 'importRoute' => '', 'params' => []])
<div class="relative" x-data="{ exportOpen: false }" @click.outside="exportOpen = false">
    <button type="button" @click="exportOpen = !exportOpen"
            class="bg-white text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100 flex items-center gap-1">
        ↓ Export/Import
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>
    <div x-show="exportOpen" x-cloak
         class="absolute right-0 top-full mt-1 bg-white border-2 border-black z-50 min-w-[160px] shadow-xl">
        <a href="{{ route($exportRoute, $params) }}"
           class="block px-4 py-2 text-sm font-['Times_New_Roman'] hover:bg-gray-100 border-b-2 border-black whitespace-nowrap">
            ↓ Export XLSX
        </a>
        <a href="{{ route($importRoute) }}"
           class="block px-4 py-2 text-sm font-['Times_New_Roman'] hover:bg-gray-100 whitespace-nowrap">
            ↑ Import XLSX
        </a>
    </div>
</div>
