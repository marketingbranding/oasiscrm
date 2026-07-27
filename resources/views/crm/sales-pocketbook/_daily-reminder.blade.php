<div x-data="salesDailyReminder(@js([
        ...$dailyReminder,
        'reminderKey' => \App\Services\SalesDailyReminderService::KEY,
    ]))"
     x-show="open" x-cloak
     @keydown.escape.window="if (open && !conflictOpen()) close()"
     @click.self="if (!conflictOpen()) close()"
     class="fixed inset-0 z-[900] flex items-center justify-center bg-black/70 p-4">
    <div x-ref="dialog" @keydown.tab="trapFocus($event)" role="dialog" aria-modal="true" aria-labelledby="sales-daily-reminder-title"
         class="w-full max-w-lg border-2 border-black bg-white shadow-[7px_7px_0_#000]">
        <div class="flex items-center justify-between border-b-2 border-black bg-black px-4 py-2 text-white">
            <h2 id="sales-daily-reminder-title" class="font-[Helvetica] text-sm font-bold uppercase">Pengingat Hari Ini</h2>
            <button type="button" @click="close()" class="text-xl font-bold leading-none" aria-label="Tutup pengingat">&times;</button>
        </div>
        <div class="space-y-3 p-4 font-['Times_New_Roman'] text-sm">
            <p>Jangan lupa mengisi lead hari ini serta planning atau agenda kerja Anda di Buku Saku Sales.</p>
            <div class="border-2 border-black bg-[#fffdf2] p-3">
                @unless($dailyReminder['hasAssignedProject'])
                    <p class="font-bold text-[#c0392b]">Anda belum ditugaskan ke proyek. Hubungi admin pusat.</p>
                @endunless
                @if($dailyReminder['todayLeadCount'] === 0 && $dailyReminder['hasAssignedProject'])
                    <p>Belum ada lead yang dicatat hari ini.</p>
                @endif
                @if($dailyReminder['todayAgendaCount'] === 0)
                    <p>Belum ada planning atau agenda hari ini.</p>
                @endif
                @if($dailyReminder['missingAgendaResultCount'] > 0)
                    <p>Ada {{ $dailyReminder['missingAgendaResultCount'] }} agenda selesai yang belum memiliki hasil.</p>
                @endif
            </div>
            <label class="flex cursor-pointer items-start gap-2 border-2 border-black bg-[#f5f5f5] p-2">
                <input type="checkbox" x-model="hideToday" class="mt-0.5">
                <span>Sembunyikan pengingat untuk hari ini</span>
            </label>
            <div class="flex flex-wrap gap-2 border-t-2 border-black pt-3">
                @if($dailyReminder['hasAssignedProject'])
                    <button type="button" :disabled="actionPending" @click="navigate(@js($dailyReminder['leadInputUrl']))" class="sales-button bg-[#fcc20f] disabled:opacity-50">Input Lead</button>
                @endif
                <button type="button" :disabled="actionPending" @click="navigate(@js($dailyReminder['agendaInputUrl']))" class="sales-button bg-[#b3bd95] disabled:opacity-50">Isi Agenda</button>
                @if($dailyReminder['missingAgendaResultCount'] > 0)
                    <button type="button" :disabled="actionPending" @click="navigate(@js($dailyReminder['missingResultUrl']))" class="sales-button bg-[#d77a7a] disabled:opacity-50">Lengkapi Hasil</button>
                @endif
                <button type="button" :disabled="actionPending" @click="close()" class="sales-button bg-white disabled:opacity-50">Nanti Saja</button>
            </div>
        </div>
    </div>
</div>
