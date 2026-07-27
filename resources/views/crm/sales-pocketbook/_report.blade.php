@php
    $metricLabels = [
        'lead_new' => 'Lead Baru', 'contacted' => 'Dihubungi', 'met' => 'Tatap Muka',
        'surveyed' => 'Survey', 'utj' => 'UTJ', 'documents_completed' => 'Berkas Lengkap', 'akad' => 'Akad',
    ];
    $conversionLabels = [
        'contacted' => 'lead_contacted', 'met' => 'contacted_met', 'surveyed' => 'met_survey',
        'utj' => 'survey_utj', 'documents_completed' => 'utj_documents', 'akad' => 'documents_akad',
    ];
    $periodParams = ['date_from' => $reportPeriod['start']->toDateString(), 'date_to' => $reportPeriod['end']->toDateString()];
    $sortUrl = function (string $column) {
        return route('sales-pocketbook.index', array_merge(request()->query(), [
            'tab' => 'report',
            'sort' => $column,
            'direction' => request('sort') === $column && request('direction', 'asc') === 'asc' ? 'desc' : 'asc',
        ]));
    };
@endphp

<form method="GET" action="{{ route('sales-pocketbook.index') }}" class="border-2 border-black bg-[#f5f5f5] p-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-2">
    <input type="hidden" name="tab" value="report">
    @if($monitoring)
        <select class="sales-input" name="branch_id"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="sales-input" name="sales_user_id"><option value="">Semua sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" @selected(request('sales_user_id') == $sales->id)>{{ $sales->name }}</option>@endforeach</select>
    @endif
    <select class="sales-input" name="project_id"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(request('project_id') == $project->id)>{{ $project->project_name }}</option>@endforeach</select>
    <div><label class="sales-label">Pilih Minggu</label><x-crm.date-field name="week" :value="request('week', $reportPeriod['start']->toDateString())" /></div>
    <div><label class="sales-label">Dari (opsional)</label><x-crm.date-field name="date_from" :value="request('date_from')" /></div>
    <div><label class="sales-label">Sampai (opsional)</label><x-crm.date-field name="date_to" :value="request('date_to')" /></div>
    <button class="sales-button bg-black text-white self-end">Tampilkan</button>
</form>

<section class="border-2 border-black bg-white">
    <div class="bg-black text-[#fcc20f] px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
        Ringkasan {{ $reportPeriod['start']->format('d/m/Y') }} - {{ $reportPeriod['end']->format('d/m/Y') }}
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2 p-3">
        @foreach($metricLabels as $key => $label)
            <div class="border-2 border-black p-2">
                <div class="sales-label">{{ $label }}</div>
                <div class="font-['Arial_Black'] text-2xl">{{ $reportSummary[$key] }}</div>
                @if(isset($conversionLabels[$key]))
                    <div class="text-[10px]">Konversi: {{ $reportSummary['conversions'][$conversionLabels[$key]] === null ? '—' : number_format($reportSummary['conversions'][$conversionLabels[$key]], 1).'%' }}</div>
                @endif
            </div>
        @endforeach
        <div class="border-2 border-black bg-[#fff3b0] p-2"><div class="sales-label">Agenda Selesai</div><div class="font-['Arial_Black'] text-2xl">{{ $reportSummary['agenda_completed'] }}</div></div>
    </div>
</section>

@if($monitoring)
<section class="border-2 border-black bg-white">
    <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">Monitoring Buku Saku</div>
    <div class="crm-table-scroll">
        <table class="crm-data-table">
            <thead><tr>
                @foreach(['sales' => 'Sales', 'branch' => 'Cabang', 'project' => 'Proyek'] as $key => $label)
                    <th><a href="{{ $sortUrl($key) }}">{{ $label }} @if(request('sort') === $key){{ request('direction', 'asc') === 'asc' ? '▲' : '▼' }}@endif</a></th>
                @endforeach
                <th>Minggu</th>
                @foreach($metricLabels as $key => $label)<th><a href="{{ $sortUrl($key) }}">{{ $label }} @if(request('sort') === $key){{ request('direction', 'asc') === 'asc' ? '▲' : '▼' }}@endif</a></th>@endforeach
                <th><a href="{{ $sortUrl('agenda_completed') }}">Agenda @if(request('sort') === 'agenda_completed'){{ request('direction', 'asc') === 'asc' ? '▲' : '▼' }}@endif</a></th>
                <th><a href="{{ $sortUrl('last_input') }}">Input Terakhir @if(request('sort') === 'last_input'){{ request('direction', 'asc') === 'asc' ? '▲' : '▼' }}@endif</a></th>
            </tr></thead>
            <tbody>
            @forelse($reportRows as $row)
                @php $drillScope = array_merge($periodParams, $row['scope']); @endphp
                <tr>
                    <td class="font-bold">{{ $row['sales']->name }}</td><td>{{ $row['branch']?->name }}</td><td>{{ $row['project']->project_name }}</td>
                    <td class="whitespace-nowrap">{{ $reportPeriod['start']->format('d/m') }} - {{ $reportPeriod['end']->format('d/m/Y') }}</td>
                    @foreach($metricLabels as $key => $label)
                        <td><a class="font-bold text-[#0000ee] underline" href="{{ route('sales-pocketbook.index', array_merge($drillScope, ['tab' => 'leads', 'report_metric' => $key])) }}">{{ $row[$key] }}</a>@if(isset($conversionLabels[$key]))<div class="text-[9px]">{{ $row['conversions'][$conversionLabels[$key]] === null ? '—' : number_format($row['conversions'][$conversionLabels[$key]], 1).'%' }}</div>@endif</td>
                    @endforeach
                    <td><a class="font-bold text-[#0000ee] underline" href="{{ route('sales-pocketbook.index', array_merge($drillScope, ['tab' => 'agenda', 'report_agenda_completed' => 1])) }}">{{ $row['agenda_completed'] }}</a></td>
                    <td class="whitespace-nowrap">{{ $row['last_input']?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @empty<tr><td colspan="13" class="py-8 text-center">Belum ada penugasan sales pada filter ini.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
</section>
@endif
