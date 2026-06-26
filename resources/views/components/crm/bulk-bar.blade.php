@props([
    'destroyRoute' => '',
    'updateRoute' => '',
    'statusOptions' => [],
    'statusLabel' => 'Status',
    'accentColor' => '#000',
    'params' => [],
])
<div id="bulk-bar" class="fixed bottom-4 left-4 z-50 bg-white border-2 border-black shadow-lg hidden">
    <div class="flex items-center gap-3 px-4 py-3">
        <span class="text-sm font-[Helvetica] font-bold"><span id="bulk-count">0</span> data terpilih</span>
        @if(!empty($statusOptions))
        <div class="h-6 w-px bg-black mx-1"></div>
        <select id="bulk-new-status" class="border-2 border-black px-2 py-1.5 text-xs font-['Times_New_Roman'] bg-white">
            @foreach($statusOptions as $value => $label)
                <option value="{{ $value }}">→ {{ $label }}</option>
            @endforeach
        </select>
        <button onclick="CrmBulk.updateStatus('{{ $updateRoute }}', '{{ $accentColor }}')"
                class="bg-[color] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:opacity-90 cursor-pointer"
                style="background-color: {{ $accentColor }};">
            Update {{ $statusLabel }}
        </button>
        <div class="h-6 w-px bg-black mx-1"></div>
        @endif
        <button onclick="CrmBulk.destroy('{{ $destroyRoute }}')"
                class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#c0392b] cursor-pointer">
            Hapus Terpilih
        </button>
    </div>
</div>

<form id="bulk-form" method="POST" action="{{ $destroyRoute }}" class="hidden">
    @csrf
    <input type="hidden" name="selected_ids" id="bulk-ids">
    @foreach($params as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
</form>

@if(!empty($statusOptions))
<form id="bulk-update-form" method="POST" action="{{ $updateRoute }}" class="hidden">
    @csrf
    <input type="hidden" name="selected_ids" id="bulk-update-ids">
    <input type="hidden" name="new_status" id="bulk-update-status">
    @foreach($params as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
</form>
@endif
