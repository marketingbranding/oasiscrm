@props(['agenda', 'canCleanup'])

@if($canCleanup)
    <div x-data>
        @php($isImpersonating = app(\App\Services\ImpersonationService::class)->isActive(request()))
        <button type="button" class="font-bold text-red-700 underline" @click="$dispatch('oasis:modal-open', { name: 'cleanup-agenda-{{ $agenda->id }}', trigger: $el })">{{ $isImpersonating ? 'Hapus sebagai Superadmin' : 'Hapus agenda' }}</button>
        @if($isImpersonating)<p class="mt-1 text-xs">Anda sedang impersonasi. Tindakan ini menggunakan wewenang Superadmin asli dan akan dicatat di audit log.</p>@endif
        <x-crm.modal name="cleanup-agenda-{{ $agenda->id }}" title="Hapus Agenda Sales" description="Tindakan permanen. Arsip atau bukti yang sudah dipurge memblokir cleanup.">
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div><dt class="font-bold">Agenda</dt><dd>{{ $agenda->title }}</dd></div>
                <div><dt class="font-bold">Sales</dt><dd>{{ $agenda->owner?->name ?: '-' }}</dd></div>
                <div><dt class="font-bold">Tanggal</dt><dd>{{ $agenda->scheduled_date?->format('d/m/Y') ?: '-' }}</dd></div>
                <div><dt class="font-bold">Kategori</dt><dd>{{ $agenda->sales_activity_category ?: '-' }}</dd></div>
                <div><dt class="font-bold">Bukti</dt><dd>{{ $agenda->evidence->count() }} file lokal</dd></div>
            </dl>
            <p class="mt-4 border-2 border-red-700 bg-red-50 p-3 text-sm font-bold">Agenda kanonik dan semua bukti lokal akan dihapus. Tindakan tidak dapat dibatalkan. Cleanup ditolak jika bukti sudah masuk arsip atau dipurge.</p>
            <form method="POST" action="{{ route('sales-agendas.cleanup', $agenda) }}" class="mt-4 space-y-3">
                @csrf @method('DELETE')
                <label class="block text-sm font-bold" for="cleanup-reason-{{ $agenda->id }}">Alasan wajib</label>
                <textarea id="cleanup-reason-{{ $agenda->id }}" name="reason" required maxlength="500" rows="3" class="sales-input w-full"></textarea>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmation" value="1" required class="mt-1"> Saya memahami agenda dan semua bukti lokal akan dihapus.</label>
                <x-crm.button type="submit" variant="danger">Konfirmasi cleanup</x-crm.button>
            </form>
        </x-crm.modal>
    </div>
@endif
