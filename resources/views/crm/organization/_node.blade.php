<div class="flex min-w-48 flex-col items-center gap-2" x-show="matches({{ $node['id'] }})">
    <button type="button" draggable="true" @dragstart="startDrag({{ $node['id'] }})" @click.stop="select({{ $node['id'] }})" @dragover.prevent @drop.stop="drop({{ $node['id'] }})"
            class="w-48 border-2 border-black bg-white p-3 text-left shadow-[4px_4px_0_#000] hover:bg-[var(--oasis-yellow)]" :class="dropTarget === {{ $node['id'] }} ? 'bg-green-200' : ''">
        <span class="block truncate font-[Helvetica] text-xs font-bold uppercase" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
        <span class="mt-1 block text-xs text-gray-700">{{ $node['role'] }}</span>
        <span class="mt-2 block font-[Helvetica] text-[10px] uppercase text-gray-500">{{ $node['direct_reports'] }} direct reports</span>
    </button>
    @if($node['children'] !== [])
        <div class="flex items-start gap-4 border-t-2 border-black pt-4">
            @foreach($node['children'] as $child)
                @include('crm.organization._node', ['node' => $child])
            @endforeach
        </div>
    @endif
</div>
