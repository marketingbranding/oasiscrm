@php($periodPickerId = $periodPickerId ?? 'sales-period')
<div class="sales-period-picker" x-data="@js([
    'open' => false,
    'type' => $periodType,
    'week' => request('week', $reportPeriod['start']->toDateString()),
    'from' => request('date_from', $periodType === 'custom' ? $reportPeriod['start']->toDateString() : null),
    'to' => request('date_to', $periodType === 'custom' ? $reportPeriod['end']->toDateString() : null),
])" @keydown.escape.window="if (open) { open = false; $nextTick(() => $refs.periodTrigger?.focus()) }">
    <input type="hidden" name="period_type" :value="type">
    <input type="hidden" name="week" :value="week" :disabled="type !== 'week'">
    <input type="hidden" name="date_from" :value="from" :disabled="type !== 'custom'">
    <input type="hidden" name="date_to" :value="to" :disabled="type !== 'custom'">
    <button x-ref="periodTrigger" type="button" class="sales-button w-full bg-white" @click.stop="open = !open" :aria-expanded="open" aria-haspopup="true" aria-controls="{{ $periodPickerId }}-panel"><span>Pilih Periode</span><span class="sales-period-picker-summary" x-text="type === 'week' ? 'Mingguan' : 'Rentang tanggal'"></span></button>
    <template x-if="open">
        <div id="{{ $periodPickerId }}-panel" role="group" aria-labelledby="{{ $periodPickerId }}-panel-title" @click.stop @click.outside="open = false" class="sales-period-picker-panel">
            <div class="mb-3 flex items-center justify-between border-b-2 border-black pb-2">
                <strong id="{{ $periodPickerId }}-panel-title" class="font-[Helvetica] text-xs uppercase">Pilih Periode</strong>
                <button type="button" class="sales-period-picker-close" aria-label="Tutup pilihan periode" @click="open = false; $refs.periodTrigger.focus()">&times;</button>
            </div>
            <div class="mb-3 grid grid-cols-2 gap-2">
                <label class="cursor-pointer border-2 border-black p-2 text-center text-xs font-bold" :class="type === 'week' ? 'bg-[#fcc20f]' : 'bg-white'"><input class="sr-only" type="radio" value="week" x-model="type"> Mingguan</label>
                <label class="cursor-pointer border-2 border-black p-2 text-center text-xs font-bold" :class="type === 'custom' ? 'bg-[#fcc20f]' : 'bg-white'"><input class="sr-only" type="radio" value="custom" x-model="type"> Tanggal</label>
            </div>
            <div x-show="type === 'week'"><label for="{{ $periodPickerId }}-week" class="sales-label">Tanggal dalam minggu</label><x-crm.date-field id="{{ $periodPickerId }}-week" name="week" x-model="week" x-bind:disabled="type !== 'week'" /></div>
            <div x-show="type === 'custom'" class="space-y-2"><div><label for="{{ $periodPickerId }}-from" class="sales-label">Tanggal mulai</label><x-crm.date-field id="{{ $periodPickerId }}-from" name="date_from" x-model="from" x-bind:disabled="type !== 'custom'" /></div><div><label for="{{ $periodPickerId }}-to" class="sales-label">Tanggal selesai</label><x-crm.date-field id="{{ $periodPickerId }}-to" name="date_to" x-model="to" x-bind:disabled="type !== 'custom'" /></div></div>
        </div>
    </template>
</div>
