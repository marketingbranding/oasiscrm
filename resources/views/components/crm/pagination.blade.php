@props(['collection' => null, 'perPage' => '15'])
<div class="flex items-center justify-between px-4 py-3 border-t-2 border-black bg-white">
    <div class="text-xs font-['Times_New_Roman']">
        @if(method_exists($collection, 'total'))
            {{ $collection->firstItem() }}–{{ $collection->lastItem() }} dari {{ $collection->total() }}
        @else
            Semua {{ $collection->count() }} data
        @endif
    </div>
    <div class="flex items-center gap-2">
        <span class="text-xs font-['Times_New_Roman']">Tampilkan</span>
        <select onchange="window.location.href=this.value"
                class="border border-black text-xs px-1 py-0.5 font-['Times_New_Roman'] bg-white">
            <option value="{{ request()->fullUrlWithQuery(['per_page' => 15]) }}" {{ ($perPage ?? '15') == '15' ? 'selected' : '' }}>15</option>
            <option value="{{ request()->fullUrlWithQuery(['per_page' => 30]) }}" {{ ($perPage ?? '15') == '30' ? 'selected' : '' }}>30</option>
            <option value="{{ request()->fullUrlWithQuery(['per_page' => 50]) }}" {{ ($perPage ?? '15') == '50' ? 'selected' : '' }}>50</option>
            <option value="{{ request()->fullUrlWithQuery(['per_page' => 100]) }}" {{ ($perPage ?? '15') == '100' ? 'selected' : '' }}>100</option>
            <option value="{{ request()->fullUrlWithQuery(['per_page' => 'all']) }}" {{ ($perPage ?? '15') == 'all' ? 'selected' : '' }}>Semua</option>
        </select>
    </div>
    <div class="flex items-center gap-1">
        @if(method_exists($collection, 'links'))
            {{ $collection->links() }}
        @endif
    </div>
</div>
