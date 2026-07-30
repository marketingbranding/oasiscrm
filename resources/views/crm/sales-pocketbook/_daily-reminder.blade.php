<div x-data="salesDailyReminder(@js([
        ...$dailyReminder,
        'reminderKey' => \App\Services\SalesDailyReminderService::KEY,
    ]))"
     x-show="open" x-cloak
     @keydown.escape.window="if (open && !conflictOpen()) close()"
     @click.self="if (!conflictOpen()) close()"
     class="sales-daily-reminder-backdrop fixed inset-0 z-[900] flex items-center justify-center bg-black/70 p-4">
    <div x-ref="dialog" @keydown.tab="trapFocus($event)" role="dialog" aria-modal="true" aria-labelledby="sales-daily-reminder-title"
         aria-describedby="sales-daily-reminder-description"
         class="sales-daily-reminder-dialog w-full max-w-lg border-2 border-black bg-white shadow-[7px_7px_0_#000]">
        <div class="sales-daily-reminder-header">
            <div><span class="sales-daily-reminder-eyebrow">Buku Saku Sales</span><h2 id="sales-daily-reminder-title">Pengingat Hari Ini</h2></div>
            <button type="button" @click="close()" class="sales-daily-reminder-close" aria-label="Tutup pengingat">&times;</button>
        </div>
        <div class="sales-daily-reminder-body">
            <p id="sales-daily-reminder-description">Jangan lupa mengisi lead hari ini serta planning atau agenda kerja Anda di Buku Saku Sales.</p>
            <div class="sales-daily-reminder-status" aria-label="Aktivitas yang perlu diperhatikan">
                @unless($dailyReminder['hasAssignedProject'])
                    <p class="sales-daily-reminder-status-item sales-daily-reminder-status-item--danger"><strong>Proyek belum tersedia</strong><span>Anda belum ditugaskan ke proyek. Hubungi admin pusat.</span></p>
                @endunless
                @if($dailyReminder['todayLeadCount'] === 0 && $dailyReminder['hasAssignedProject'])
                    <p class="sales-daily-reminder-status-item"><strong>Lead hari ini</strong><span>Belum ada lead yang dicatat hari ini.</span></p>
                @endif
                @if($dailyReminder['todayAgendaCount'] === 0)
                    <p class="sales-daily-reminder-status-item"><strong>Agenda hari ini</strong><span>Belum ada planning atau agenda hari ini.</span></p>
                @endif
                @if($dailyReminder['missingAgendaResultCount'] > 0)
                    <p class="sales-daily-reminder-status-item sales-daily-reminder-status-item--warning"><strong>Hasil belum lengkap</strong><span>Ada {{ $dailyReminder['missingAgendaResultCount'] }} agenda selesai yang belum memiliki hasil.</span></p>
                @endif
            </div>
            <label for="sales-daily-reminder-hide-today" class="sales-daily-reminder-option">
                <input id="sales-daily-reminder-hide-today" type="checkbox" x-model="hideToday">
                <span>Sembunyikan pengingat untuk hari ini</span>
            </label>
            <div class="sales-daily-reminder-actions">
                @if($dailyReminder['hasAssignedProject'])
                    <x-crm.button type="button" variant="primary" accent="sales" ::disabled="actionPending" ::aria-busy="actionPending" @click="navigate(@js($dailyReminder['leadInputUrl']))">Input Lead</x-crm.button>
                @endif
                <button type="button" :disabled="actionPending" :aria-busy="actionPending" @click="navigate(@js($dailyReminder['agendaInputUrl']))" class="crm-button crm-button--secondary crm-button--md">Isi Agenda</button>
                @if($dailyReminder['missingAgendaResultCount'] > 0)
                    <x-crm.button type="button" variant="secondary" ::disabled="actionPending" ::aria-busy="actionPending" @click="navigate(@js($dailyReminder['missingResultUrl']))">Lengkapi Hasil</x-crm.button>
                @endif
                <x-crm.button type="button" variant="ghost" ::disabled="actionPending" ::aria-busy="actionPending" @click="close()">Nanti Saja</x-crm.button>
                <span class="sr-only" aria-live="polite" x-text="actionPending ? 'Tindakan sedang diproses.' : ''"></span>
            </div>
        </div>
    </div>
</div>
