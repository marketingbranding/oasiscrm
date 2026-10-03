<div class="border-l-2 border-black pl-3" style="margin-left: {{ $depth * 12 }}px">
    <button type="button" @click="select({{ $node['id'] }})" class="flex w-full items-center justify-between gap-3 border-2 border-black bg-white p-3 text-left">
        <span><strong class="block text-sm">{{ $node['name'] }}</strong><span class="text-xs text-gray-600">{{ $node['role'] }}</span></span>
        <span class="font-[Helvetica] text-xs text-gray-600">{{ $node['direct_reports'] }}</span>
    </button>
    @foreach($node['children'] as $child)
        @include('crm.organization._mobile-node', ['node' => $child, 'depth' => $depth + 1])
    @endforeach
</div>
