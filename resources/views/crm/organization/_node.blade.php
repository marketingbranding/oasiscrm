<div class="org-node-item absolute" x-show="matches({{ $node['id'] }})" :style="nodeStyle({{ $node['id'] }})">
    <div class="relative w-48">
        @if($canMove)
            <button type="button" data-org-socket data-org-socket-node="{{ $node['id'] }}" data-org-socket-type="input"
                    @pointerdown.stop="startConnection($event, {{ $node['id'] }}, 'input')"
                    class="org-socket org-socket-input"
                    :class="connectionSocketClass({{ $node['id'] }}, 'input')"
                    title="Tarik untuk mengubah atasan {{ $node['name'] }}"
                    aria-label="Tarik socket Atasan untuk {{ $node['name'] }}"></button>
        @endif
        <button type="button" data-org-node="{{ $node['id'] }}"
                @pointerdown.stop="startVisualDrag($event, {{ $node['id'] }})"
                @click.stop="if (!suppressClick) select({{ $node['id'] }})"
                @contextmenu.prevent.stop="openContext($event, {{ $node['id'] }})"
                class="org-node-card w-48 border-2 border-black bg-white p-3 text-left shadow-[4px_4px_0_#000] hover:bg-[var(--oasis-yellow)]"
                :class="selected?.id === {{ $node['id'] }} ? 'ring-4 ring-[var(--oasis-focus)] ring-offset-2' : ''"
                aria-label="Pilih {{ $node['name'] }}. Klik kanan untuk tindakan.">
            <span class="block truncate font-[Helvetica] text-xs font-bold uppercase" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
            <span class="mt-1 block text-xs text-gray-700">{{ $node['role'] }}</span>
            <span class="mt-2 block font-[Helvetica] text-[10px] uppercase text-gray-500">{{ $node['direct_reports'] }} direct reports</span>
        </button>
        @if($canMove)
            <button type="button" data-org-socket data-org-socket-node="{{ $node['id'] }}" data-org-socket-type="output"
                    @pointerdown.stop="startConnection($event, {{ $node['id'] }}, 'output')"
                    class="org-socket org-socket-output"
                    :class="connectionSocketClass({{ $node['id'] }}, 'output')"
                    title="Tarik untuk menambahkan bawahan di bawah {{ $node['name'] }}"
                    aria-label="Tarik socket Bawahan dari {{ $node['name'] }}"></button>
        @endif
    </div>
</div>
