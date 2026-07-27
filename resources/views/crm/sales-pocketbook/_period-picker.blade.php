<div class="relative" x-data="@js([
    'open' => false,
    'type' => $periodType,
    'week' => request('week', $reportPeriod['start']->toDateString()),
    'from' => request('date_from', $periodType === 'custom' ? $reportPeriod['start']->toDateString() : null),
    'to' => request('date_to', $periodType === 'custom' ? $reportPeriod['end']->toDateString() : null),
])" @keydown.escape.window="open = false">
    <input type="hidden" name="period_type" :value="type">
    <input type="hidden" name="week" :value="week" :disabled="type !== 'week'">
    <input type="hidden" name="date_from" :value="from" :disabled="type !== 'custom'">
    <input type="hidden" name="date_to" :value="to" :disabled="type !== 'custom'">
    <button type="button" class="sales-button w-full bg-white" @click.stop="open = !open" :aria-expanded="open" aria-controls="sales-period-panel">Pilih Periode</button>
    <template x-if="open">
        <div id="sales-period-panel" @click.stop @click.outside="open = false" class="absolute right-0 top-full z-[100] mt-1 w-[280px] border-2 border-black bg-white p-3 shadow-[3px_3px_0_#000]">
            <div class="mb-3 flex items-center justify-between border-b-2 border-black pb-2">
                <strong class="font-[Helvetica] text-xs uppercase">Pilih Periode</strong>
                <button type="button" class="px-2 text-xl font-bold leading-none" aria-label="Tutup pilihan periode" @click="open = false">&times;</button>
            </div>
            <div class="mb-3 grid grid-cols-2 gap-2">
                <label class="cursor-pointer border-2 border-black p-2 text-center text-xs font-bold" :class="type === 'week' ? 'bg-[#fcc20f]' : 'bg-white'"><input class="sr-only" type="radio" value="week" x-model="type"> Mingguan</label>
                <label class="cursor-pointer border-2 border-black p-2 text-center text-xs font-bold" :class="type === 'custom' ? 'bg-[#fcc20f]' : 'bg-white'"><input class="sr-only" type="radio" value="custom" x-model="type"> Tanggal</label>
            </div>
            <div x-show="type === 'week'"><label class="sales-label">Pilih Minggu</label><x-crm.date-field name="week" x-model="week" x-bind:disabled="type !== 'week'" /></div>
            <div x-show="type === 'custom'" class="space-y-2"><div><label class="sales-label">Dari</label><x-crm.date-field name="date_from" x-model="from" x-bind:disabled="type !== 'custom'" /></div><div><label class="sales-label">Sampai</label><x-crm.date-field name="date_to" x-model="to" x-bind:disabled="type !== 'custom'" /></div></div>
        </div>
    </template>
</div>
