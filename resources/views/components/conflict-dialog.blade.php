@props(['initial' => null])

<div x-data="crmConflict({ initial: @js($initial) })"
     @oasis-conflict.window="handleConflict($event.detail.response, $event.detail.context || {})"
     @oasis-submit-conflict.window="submitForm($event.detail.form, $event.detail.context || {})"
     @keydown.escape.window="if (open) { $event.preventDefault(); closeConflict() }">
    <div id="oasis-conflict-dialog" x-show="open" x-cloak class="fixed inset-0 z-[1000] flex items-center justify-center bg-black/70 px-4">
        <div x-ref="dialog" @keydown.tab="trapFocus($event)" class="w-full max-w-lg border-2 border-black bg-white shadow-[8px_8px_0_0_#000]" role="dialog" aria-modal="true" aria-label="Konflik perubahan data">
            <div class="flex items-center justify-between bg-[#fcc20f] border-b-2 border-black px-4 py-2">
                <strong class="font-[Helvetica] text-sm uppercase">Data Sudah Berubah</strong>
                <button type="button" @click="closeConflict()" class="font-bold text-xl leading-none" aria-label="Tutup">&times;</button>
            </div>
            <div class="p-4 space-y-3 font-['Times_New_Roman'] text-sm">
                <p class="font-bold" x-text="conflict?.message"></p>
                <p x-show="conflict?.modified_by?.display_name">
                    Data ini telah diperbarui oleh <strong x-text="conflict?.modified_by?.display_name"></strong>
                    <span x-show="conflict?.current_updated_label"> pada <span x-text="conflict?.current_updated_label"></span></span>.
                </p>
                <p x-show="!conflict?.modified_by && conflict?.current_updated_label">Terakhir diperbarui pada <strong x-text="conflict?.current_updated_label"></strong>.</p>
                <div class="border-2 border-black bg-[#fff3b0] px-3 py-2">
                    Muat ulang data akan mengganti nilai pada form saat ini. Salin perubahan Anda terlebih dahulu bila masih diperlukan.
                </div>
                <p x-show="validationMessage" class="border-2 border-black bg-[#d77a7a] px-3 py-2" x-text="validationMessage"></p>
                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="button" @click="reloadConflictRecord()" class="border-2 border-black bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs">Muat Ulang Data</button>
                    <button type="button" @click="copyUnsavedValues()" class="border-2 border-black bg-white px-4 py-2 font-[Helvetica] font-bold text-xs">Salin Perubahan Saya</button>
                    <button type="button" @click="closeConflict()" class="border-2 border-black bg-white px-4 py-2 font-[Helvetica] font-bold text-xs">Kembali ke Form</button>
                    <button type="button" @click="discardSavedValues()" class="border-2 border-black bg-white px-4 py-2 font-[Helvetica] font-bold text-xs text-[#c0392b]">Buang Salinan</button>
                </div>
                <p x-show="copied" class="text-[#176b32] font-bold">Perubahan Anda telah disalin.</p>
                <p x-show="copyError" class="border-2 border-black bg-[#d77a7a] px-3 py-2 font-bold" x-text="copyError"></p>
            </div>
        </div>
    </div>
</div>
