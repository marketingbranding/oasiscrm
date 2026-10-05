<div class="org-node-item absolute" x-show="matches(@js($node['id']))" :style="nodeStyle(@js($node['id']))">
    <div class="relative w-48">
        @if($canMove && $node['kind'] === 'user')
            <div class="org-socket-row org-socket-row-input org-socket-row-atasan">
                <button type="button" data-org-socket data-org-socket-node="{{ $node['id'] }}" data-org-socket-type="input"
                        @pointerdown.stop="startConnection($event, @js($node['id']), 'input')"
                        class="org-socket org-socket-input"
                        :class="connectionSocketClass(@js($node['id']), 'input')"
                        title="Tarik untuk mengubah atasan {{ $node['name'] }}"
                        aria-label="Tarik socket Atasan untuk {{ $node['name'] }}"></button>
                <span class="org-socket-label">Atasan</span>
            </div>
        @endif
        <button type="button" data-org-node="{{ $node['id'] }}" @if($node['kind'] === 'unit') data-org-unit-drop="{{ $node['id'] }}" @endif
                @pointerdown.stop="startVisualDrag($event, @js($node['id']))"
                @click.stop="if (!suppressClick) select(@js($node['id']))"
                @contextmenu.prevent.stop="openContext($event, @js($node['id']))"
                class="org-node-card {{ $node['kind'] === 'user' ? 'org-node-card-user' : 'org-node-card-unit' }} w-72 border-2 border-black bg-white p-3 text-left shadow-[4px_4px_0_#000] hover:bg-[var(--oasis-yellow)]"
                :class="[selected?.id === @js($node['id']) ? 'ring-4 ring-[var(--oasis-focus)] ring-offset-2' : '', unitMoveTarget?.unitId === @js($node['unit_id'] ?? null) ? 'org-unit-drop-target' : '']"
                aria-label="Pilih {{ $node['name'] }}. Klik kanan untuk tindakan.">
            <span class="block truncate font-[Helvetica] text-xs font-bold uppercase" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
            <span class="mt-1 block text-xs text-gray-700">{{ $node['role'] }}</span>
            @if($node['kind'] === 'unit')
                <span class="mt-2 block font-[Helvetica] text-[10px] uppercase text-gray-500">{{ $node['member_count'] }} members · {{ $node['direct_reports'] }} direct reports</span>
            @else
                <span class="mt-2 block font-[Helvetica] text-[10px] uppercase text-gray-500">{{ $node['direct_reports'] }} direct reports</span>
            @endif
        </button>
        @if($canMove && $node['kind'] === 'user')
            <div class="org-socket-row org-socket-row-output org-socket-row-bawahan">
                <span class="org-socket-label">Bawahan</span>
                <button type="button" data-org-socket data-org-socket-node="{{ $node['id'] }}" data-org-socket-type="output"
                        @pointerdown.stop="startConnection($event, @js($node['id']), 'output')"
                        class="org-socket org-socket-output"
                        :class="connectionSocketClass(@js($node['id']), 'output')"
                        title="Tarik untuk menambahkan bawahan di bawah {{ $node['name'] }}"
                        aria-label="Tarik socket Bawahan dari {{ $node['name'] }}"></button>
            </div>
            <div class="org-socket-row org-socket-row-output org-socket-row-unit">
                <span class="org-socket-label">Unit</span>
                <button type="button" data-org-unit-source="{{ $node['id'] }}"
                        @pointerdown.stop="startUnitConnection($event, @js($node['id']))"
                        class="org-unit-socket org-unit-socket-source"
                        :class="unitSocketClass(@js($node['id']))"
                        title="Tarik ke Pusat atau Cabang untuk memindahkan unit"
                        aria-label="Tarik socket Unit untuk {{ $node['name'] }}"></button>
            </div>
        @endif
        @if($canMove && $node['kind'] === 'unit' && $node['unit_id'] !== null)
            <div class="org-socket-row org-socket-row-input org-socket-row-anggota">
                <button type="button" data-org-unit-target="{{ $node['id'] }}"
                        @pointerdown.stop
                        class="org-unit-socket org-unit-socket-target {{ $node['role'] === 'Pusat organisasi' ? 'org-unit-socket-central' : 'org-unit-socket-branch' }}"
                        :class="unitSocketClass(@js($node['id']), true)"
                        title="Drop user ke {{ $node['name'] }}"
                        aria-label="Target Anggota unit {{ $node['name'] }}"></button>
                <span class="org-socket-label">Anggota</span>
            </div>
        @endif
    </div>
</div>
