@props(['pageKey', 'branchId' => null, 'recordType' => null, 'recordId' => null, 'mode' => 'viewing'])

@if(config('presence.enabled', true))
<div x-data="crmPresence(@js([
        'enabled' => true,
        'heartbeatUrl' => route('presence.heartbeat'),
        'indexUrl' => route('presence.index'),
        'destroyUrl' => route('presence.destroy'),
        'heartbeatSeconds' => config('presence.heartbeat_seconds', 25),
        'pageKey' => $pageKey,
        'branchId' => $branchId,
        'recordType' => $recordType,
        'recordId' => $recordId,
        'mode' => $mode,
    ]))" x-show="others.length" x-cloak
     class="mb-4 border-2 border-black bg-[#eef1ff] px-3 py-2 text-xs font-[Helvetica]" :title="fullNames">
    <span class="font-bold" x-text="summary"></span>
    <span x-show="mode === 'editing'" class="block mt-1 text-[#8a4b08]">Perubahan terakhir akan diperiksa saat Anda menyimpan.</span>
</div>
@endif
