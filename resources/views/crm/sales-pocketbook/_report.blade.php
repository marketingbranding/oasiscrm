@php
    $metricLabels = [
        'lead_new' => 'Lead Baru', 'contacted' => 'Dihubungi', 'met' => 'Tatap Muka',
        'surveyed' => 'Survey', 'utj' => 'UTJ', 'documents_completed' => 'Berkas Lengkap', 'akad' => 'Akad',
    ];
    $conversionLabels = [
        'contacted' => 'lead_contacted', 'met' => 'contacted_met', 'surveyed' => 'met_survey',
        'utj' => 'survey_utj', 'documents_completed' => 'utj_documents', 'akad' => 'documents_akad',
    ];
    $periodParams = ['period_type' => 'custom', 'date_from' => $reportPeriod['start']->toDateString(), 'date_to' => $reportPeriod['end']->toDateString()];
    $reportResetUrl = route('sales-pocketbook.index', ['tab' => 'report']);
    $hasExplicitReportPeriod = collect(['period_type', 'week', 'date_from', 'date_to'])
        ->contains(fn (string $key) => request()->filled($key));
    $activeReportFilterCount = collect(['branch_id', 'project_id', 'sales_user_id'])
        ->filter(fn (string $key) => request()->filled($key))->count() + ($hasExplicitReportPeriod ? 1 : 0);
    $hasReportFilters = $activeReportFilterCount > 0;
    $reportFilterUrl = fn (array $remove) => route('sales-pocketbook.index', array_merge(
        request()->except(array_merge($remove, ['page', 'agenda_page'])),
        ['tab' => 'report'],
    ));
    $reportHasData = collect(array_keys($metricLabels))
        ->contains(fn (string $key) => (int) $reportSummary[$key] > 0)
        || (int) $reportSummary['agenda_completed'] > 0;
    $reportPeriodLabel = $reportPeriod['start']->format('d/m/Y').' - '.$reportPeriod['end']->format('d/m/Y');
    $reportSort = request('sort', 'sales');
    $reportDirection = request('direction', 'asc');
@endphp

<x-crm.toolbar label="Filter laporan Buku Saku" class="sales-report-toolbar">
    <div class="sales-report-filter-summary">
        <strong>Periode laporan</strong>
        <span>{{ $reportPeriodLabel }}</span>
    </div>
    @if($monitoring && $selectedBranch)<x-crm.filter-chip label="Cabang: {{ $selectedBranch->name }}" :remove-href="$reportFilterUrl(['branch_id', 'project_id', 'sales_user_id'])" remove-label="Hapus filter cabang, proyek, dan Sales" />@endif
    @if($selectedProject)<x-crm.filter-chip label="Proyek: {{ $selectedProject->project_name }}" :remove-href="$reportFilterUrl(['project_id', 'sales_user_id'])" remove-label="Hapus filter proyek dan Sales" />@endif
    @if($monitoring && $selectedSales)<x-crm.filter-chip label="Sales: {{ $selectedSales->name }}" :remove-href="$reportFilterUrl(['sales_user_id'])" remove-label="Hapus filter Sales" />@endif
    @if($hasExplicitReportPeriod)<x-crm.filter-chip label="Periode: {{ $reportPeriodLabel }}" :remove-href="$reportFilterUrl(['period_type', 'week', 'date_from', 'date_to'])" remove-label="Kembali ke periode laporan bawaan" />@endif
    <x-slot:actions>
        @if($hasReportFilters)<x-crm.button variant="text" :href="$reportResetUrl">Hapus semua filter</x-crm.button>@endif
        @if($monitoring)
            <x-crm.button variant="secondary" @click="$dispatch('oasis:modal-open', { name: 'sales-report-filters' })">Filter laporan{{ $activeReportFilterCount ? ' ('.$activeReportFilterCount.')' : '' }}</x-crm.button>
        @endif
    </x-slot:actions>
</x-crm.toolbar>

@if($monitoring)
    <x-crm.modal name="sales-report-filters" title="Filter Laporan Buku Saku" description="Batasi laporan berdasarkan cabang, proyek, Sales, dan periode." size="lg">
        <form method="GET" action="{{ route('sales-pocketbook.index') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => request('branch_id'), 'project' => request('project_id'), 'sales' => request('sales_user_id')]))" class="sales-report-filter-form">
            <input type="hidden" name="tab" value="report">
            <x-crm.field label="Cabang" for="report-filter-branch"><select id="report-filter-branch" class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Proyek" for="report-filter-project"><select id="report-filter-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Sales" for="report-filter-sales"><select id="report-filter-sales" class="sales-input" name="sales_user_id" x-model="sales"><option value="">Semua sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select></x-crm.field>
            <div class="crm-field"><span class="crm-field-label">Periode</span><div class="crm-field-control">@include('crm.sales-pocketbook._period-picker')</div></div>
            <div class="sales-report-filter-actions">
                <x-crm.button type="submit" variant="primary" accent="sales">Terapkan Filter</x-crm.button>
                <x-crm.button variant="secondary" :href="$reportResetUrl">Hapus semua filter</x-crm.button>
            </div>
        </form>
    </x-crm.modal>
@else
    <form method="GET" action="{{ route('sales-pocketbook.index') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => request('branch_id'), 'project' => request('project_id'), 'sales' => request('sales_user_id')]))" class="sales-report-personal-filters">
        <input type="hidden" name="tab" value="report">
        <x-crm.field label="Proyek" for="report-filter-project"><select id="report-filter-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
        <div class="crm-field"><span class="crm-field-label">Periode</span><div class="crm-field-control">@include('crm.sales-pocketbook._period-picker')</div></div>
        <div class="sales-report-filter-actions"><x-crm.button type="submit" variant="primary" accent="sales">Terapkan Filter</x-crm.button></div>
    </form>
@endif

<section class="sales-report-summary" aria-labelledby="sales-report-summary-title">
    <header class="sales-report-section-header">
        <div>
            <span>Ringkasan periode</span>
            <h2 id="sales-report-summary-title">{{ $reportPeriodLabel }}</h2>
        </div>
        <span>{{ $monitoring ? 'Scope monitoring' : 'Aktivitas saya' }}</span>
    </header>
    <div class="sales-report-metrics">
        @foreach($metricLabels as $key => $label)
            <article class="sales-report-metric">
                <h3>{{ $label }}</h3>
                <strong>{{ $reportSummary[$key] }}</strong>
                @if(isset($conversionLabels[$key]))
                    <p>Konversi: {{ $reportSummary['conversions'][$conversionLabels[$key]] === null ? '—' : number_format($reportSummary['conversions'][$conversionLabels[$key]], 1).'%' }}</p>
                @endif
            </article>
        @endforeach
        <article class="sales-report-metric sales-report-metric--agenda"><h3>Agenda Selesai</h3><strong>{{ $reportSummary['agenda_completed'] }}</strong></article>
    </div>
    @unless($reportHasData)
        <x-crm.empty-state title="Belum ada aktivitas pada periode ini." description="Metrik akan terisi setelah lead atau agenda selesai tercatat dalam scope dan periode yang dipilih." class="sales-report-zero-state" />
    @endunless
</section>

@if($monitoring)
<section class="sales-report-monitor" aria-labelledby="sales-report-monitor-title">
    <header class="sales-report-section-header">
        <div><span>Perbandingan operasional</span><h2 id="sales-report-monitor-title">Monitoring Buku Saku</h2></div>
        <span>{{ $reportRows->count() }} baris</span>
    </header>
    <div class="crm-table-scroll sales-report-table-scroll">
        <table class="crm-data-table sales-report-table">
            <thead><tr>
                @foreach(['sales' => 'Sales', 'branch' => 'Cabang', 'project' => 'Proyek'] as $key => $label)
                    <x-crm.click-sort-th :field="$key" route="sales-pocketbook.index" :label="$label" :current-sort="$reportSort" :current-dir="$reportDirection" direction-param="direction" :reset-page-keys="['page', 'agenda_page']" :current-indicator="true" />
                @endforeach
                <th scope="col">Minggu</th>
                @foreach($metricLabels as $key => $label)
                    <x-crm.click-sort-th :field="$key" route="sales-pocketbook.index" :label="$label" :current-sort="$reportSort" :current-dir="$reportDirection" direction-param="direction" :reset-page-keys="['page', 'agenda_page']" :current-indicator="true" align="right" />
                @endforeach
                <x-crm.click-sort-th field="agenda_completed" route="sales-pocketbook.index" label="Agenda" :current-sort="$reportSort" :current-dir="$reportDirection" direction-param="direction" :reset-page-keys="['page', 'agenda_page']" :current-indicator="true" align="right" />
                <x-crm.click-sort-th field="last_input" route="sales-pocketbook.index" label="Input Terakhir" :current-sort="$reportSort" :current-dir="$reportDirection" direction-param="direction" :reset-page-keys="['page', 'agenda_page']" :current-indicator="true" />
            </tr></thead>
            <tbody>
            @forelse($reportRows as $row)
                @php $drillScope = array_merge($periodParams, $row['scope']); @endphp
                <tr>
                    <td class="font-bold" title="{{ $row['sales']->name }}">{{ $row['sales']->name }}</td>
                    <td title="{{ $row['branch']?->name }}">{{ $row['branch']?->name }}</td>
                    <td title="{{ $row['project']->project_name }}">{{ $row['project']->project_name }}</td>
                    <td class="whitespace-nowrap">{{ $reportPeriod['start']->format('d/m') }} - {{ $reportPeriod['end']->format('d/m/Y') }}</td>
                    @foreach($metricLabels as $key => $label)
                        <td class="sales-report-number"><a class="crm-table-action crm-table-action--edit" aria-label="Buka detail {{ $label }} untuk {{ $row['sales']->name }}" href="{{ route('sales-pocketbook.index', array_merge($drillScope, ['tab' => 'leads', 'report_metric' => $key])) }}">{{ $row[$key] }}</a>@if(isset($conversionLabels[$key]))<span class="sales-report-conversion">{{ $row['conversions'][$conversionLabels[$key]] === null ? '—' : number_format($row['conversions'][$conversionLabels[$key]], 1).'%' }}</span>@endif</td>
                    @endforeach
                    <td class="sales-report-number"><a class="crm-table-action crm-table-action--edit" aria-label="Buka detail Agenda selesai untuk {{ $row['sales']->name }}" href="{{ route('sales-pocketbook.index', array_merge($drillScope, ['tab' => 'agenda', 'report_agenda_completed' => 1])) }}">{{ $row['agenda_completed'] }}</a></td>
                    <td class="whitespace-nowrap">{{ $row['last_input']?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="13"><x-crm.empty-state title="Belum ada penugasan Sales pada filter ini." description="Ubah filter laporan untuk melihat penugasan lain dalam akses Anda." class="sales-report-table-empty" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endif
