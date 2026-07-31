<div x-data="oasisPwa()" aria-live="polite">
    <div x-show="updateAvailable && !updateDismissed" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         role="status"
         class="fixed left-1/2 top-16 z-[1000] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 border-2 border-black bg-white p-3 shadow-[6px_6px_0_0_#000]">
        <div class="flex items-center gap-3">
            <p class="min-w-0 flex-1 font-[Helvetica] text-xs font-bold">Versi baru OASIS tersedia.</p>
            <button type="button" @click="applyUpdate()" class="shrink-0 border-2 border-black bg-black px-3 py-1.5 font-[Helvetica] text-xs font-bold text-white hover:bg-gray-800">
                Perbarui sekarang
            </button>
            <button type="button" @click="dismissUpdate()" class="shrink-0 text-lg font-bold leading-none text-black/60 hover:text-black" aria-label="Tutup pemberitahuan pembaruan">&times;</button>
        </div>
    </div>

    <div x-show="installable && !standalone && !installDismissed" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed bottom-4 left-1/2 z-[500] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 border-2 border-black bg-black p-3 text-white shadow-[6px_6px_0_0_#fcc20f]">
        <div class="flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <p class="font-[Helvetica] text-xs font-bold">Pasang OASIS</p>
                <p class="mt-0.5 font-[Helvetica] text-[11px] text-gray-300">Pasang OASIS di layar utama agar lebih cepat dibuka.</p>
            </div>
            <button type="button" @click="install()" class="shrink-0 border-2 border-black bg-[#fcc20f] px-3 py-1.5 font-[Helvetica] text-xs font-bold text-black hover:bg-[#d4ac0d]">
                Pasang
            </button>
            <button type="button" @click="dismissInstall()" class="shrink-0 text-lg font-bold leading-none text-gray-400 hover:text-white" aria-label="Tutup ajakan pasang aplikasi">&times;</button>
        </div>
    </div>
</div>
