@extends('layouts.crm')

@section('title', 'Buku Saku Sales - Oasis CRM')

@section('content')
@php
    $scopeParams = array_filter(request()->only(['branch_id', 'project_id', 'sales_user_id']), fn ($value) => filled($value));
    $periodParams = array_filter(request()->only(['period_type', 'week', 'date_from', 'date_to']), fn ($value) => filled($value));
    $tabUrl = fn (string $target) => route('sales-pocketbook.index', array_merge(
        ['tab' => $target],
        $scopeParams,
        in_array($target, ['agenda', 'report'], true) ? $periodParams : [],
    ));
    $selectedBranch = $selectedBranchId ? $branches->firstWhere('id', $selectedBranchId) : null;
    $selectedProject = $selectedProjectId ? $projects->firstWhere('id', $selectedProjectId) : null;
    $selectedSales = $monitoring && request()->filled('sales_user_id')
        ? $salesUsers->firstWhere('id', request()->integer('sales_user_id'))
        : null;
    $canExport = Auth::user()->hasPermission('sales_pocketbook.export')
        && Auth::user()->hasScopedPermission('sales_pocketbook', 'export');
    $exportUrl = $canExport ? route('sales-pocketbook.export', array_merge(
        request()->except(['page', 'agenda_page', 'sort', 'direction', 'week', 'date_from', 'date_to', 'period_type']),
        $periodType === 'custom'
            ? ['period_type' => 'custom', 'date_from' => $reportPeriod['start']->toDateString(), 'date_to' => $reportPeriod['end']->toDateString()]
            : ['period_type' => 'week', 'week' => request('week', $reportPeriod['start']->toDateString())],
    )) : null;
    $personalBranchName = $branches->firstWhere('id', Auth::user()->branch_id)?->name ?? 'Belum dipilih';
@endphp
<div class="space-y-4" x-data="salesPocketbook()">
    <x-crm.page-header
        variant="canonical"
        class="sales-pocketbook-page-header"
        eyebrow="{{ $monitoring ? 'Workspace Monitoring' : 'Workspace Pribadi' }}"
        title="Buku Saku Sales"
        description="{{ $monitoring ? 'Pantau aktivitas, funnel, dan kedisiplinan input Sales dalam scope organisasi Anda.' : 'Catat lead, jalankan agenda, dan lengkapi hasil aktivitas harian Anda.' }}"
    >
        @if(!$monitoring && $canCreate && $projects->isNotEmpty())
            <x-slot:actions>
                <x-crm.button variant="primary" accent="sales" :href="$tabUrl('leads').'#quick-lead-input'">Input Lead</x-crm.button>
                @if($canExport)
                    <x-crm.button variant="secondary" :href="$exportUrl">Export XLSX</x-crm.button>
                @endif
            </x-slot:actions>
        @elseif($canExport)
            <x-slot:actions>
                <x-crm.button variant="secondary" :href="$exportUrl">Export XLSX</x-crm.button>
            </x-slot:actions>
        @endif
    </x-crm.page-header>

    <div class="sales-pocketbook-scope" aria-label="Konteks Buku Saku Sales aktif">
        <div><span>Mode</span><strong>{{ $monitoring ? 'Monitoring' : 'Pribadi' }}</strong></div>
        <div><span>Cabang</span><strong>{{ $selectedBranch?->name ?? ($monitoring ? 'Semua dalam akses' : $personalBranchName) }}</strong></div>
        <div><span>Proyek</span><strong>{{ $selectedProject?->project_name ?? 'Semua dalam akses' }}</strong></div>
        <div><span>Sales</span><strong>{{ $monitoring ? ($selectedSales?->name ?? 'Semua dalam akses') : Auth::user()->name }}</strong></div>
    </div>
    <x-crm.page-presence page-key="sales-pocketbook" :branch-id="$selectedBranchId" />
    @if(Auth::user()->isSales())
        @include('crm.sales-pocketbook._daily-reminder')
    @endif

    @if($errors->any())<div class="border-2 border-[#c0392b] bg-red-50 px-4 py-2 text-sm"><strong>Data belum tersimpan.</strong> {{ $errors->first() }}</div>@endif
    @if(session('duplicate_warning'))
        <div class="border-2 border-[#b8860b] bg-yellow-50 p-3 text-sm"><strong>Nomor ini juga ditemukan pada lead lain yang dapat Anda akses:</strong>
            @foreach(session('duplicate_warning') as $match)<div>{{ $match['sales'] }} / {{ $match['branch'] }} / {{ $match['project'] }} / {{ $match['date'] }}</div>@endforeach
        </div>
    @endif

    @if($branches->isEmpty())
        <div class="border-2 border-black bg-[#d77a7a] px-4 py-3 font-['Times_New_Roman'] text-sm">Anda belum memiliki akses cabang.</div>
    @elseif(Auth::user()->isSales() && $projects->isEmpty())
        <div class="border-2 border-black bg-[#fcc20f] px-4 py-3 font-['Times_New_Roman'] text-sm">Anda belum ditugaskan ke proyek. Hubungi admin pusat.</div>
    @endif

    <nav class="sales-pocketbook-tabs" aria-label="Tampilan Buku Saku Sales">
        @foreach(['leads' => 'Lead', 'agenda' => 'Agenda', 'report' => 'Laporan'] as $key => $label)
            <a href="{{ $tabUrl($key) }}"
               @if($tab === $key) aria-current="page" @endif
               class="sales-pocketbook-tab {{ $tab === $key ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab === 'report')
        @include('crm.sales-pocketbook._report')
    @elseif($tab === 'agenda')
    @php
        $agendaProjectId = old('project_id', request('project_id', $defaultProject?->id));
        $agendaProject = $projects->firstWhere('id', (int) $agendaProjectId);
        $agendaOwnerId = old('owner_user_id', Auth::user()->isSales()
            ? Auth::id()
            : $agendaProject?->assignedUsers->first(fn ($assigned) => $salesUsers->contains('id', $assigned->id))?->id);
    @endphp
    @if($projects->isNotEmpty() && $salesUsers->isNotEmpty())
    <section id="quick-agenda-input" class="border-2 border-black bg-white">
        <div class="bg-black text-[#fcc20f] px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">+ Isi Agenda / Hasil</div>
        <form method="POST" action="{{ route('sales-agendas.store') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => old('branch_id', $defaultProject?->branch_id), 'project' => $agendaProjectId, 'sales' => $agendaOwnerId]))" class="p-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            @csrf
            <div><label class="sales-label">Tanggal Agenda</label><x-crm.date-field name="scheduled_date" :value="old('scheduled_date', now()->toDateString())" required /></div>
            <div><label class="sales-label">Jam Mulai</label><x-crm.time-field name="start_time" :value="old('start_time')" required /></div>
            <div><label class="sales-label">Jam Selesai</label><x-crm.time-field name="end_time" :value="old('end_time')" required /></div>
            <div><label class="sales-label">Kategori Aktivitas</label><select class="sales-input" name="sales_activity_category" required><option value="">Pilih kategori</option>@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option value="{{ $category }}" @selected(old('sales_activity_category') === $category)>{{ $category }}</option>@endforeach</select></div>
            <div><label class="sales-label">Judul Agenda</label><input class="sales-input" name="title" value="{{ old('title') }}" required></div>
            <div><label class="sales-label">Cabang</label><select class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
            <div><label class="sales-label">Proyek</label><select class="sales-input" name="project_id" x-model="project" @change="projectChanged()" required><option value="">Pilih proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></div>
            <div><label class="sales-label">Sales</label><select class="sales-input" name="owner_user_id" x-model="sales" required @disabled(!$monitoring)>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select>@unless($monitoring)<input type="hidden" name="owner_user_id" value="{{ Auth::id() }}">@endunless</div>
            <div><label class="sales-label">Lokasi</label><input class="sales-input" name="location" value="{{ old('location') }}"></div>
            <div class="sm:col-span-2 xl:col-span-3"><label class="sales-label">Catatan</label><textarea class="sales-input" name="notes" rows="2">{{ old('notes') }}</textarea></div>
            <div class="xl:col-span-4"><button class="sales-button bg-[#fcc20f]">Simpan Agenda</button></div>
        </form>
    </section>
    @endif

    <form method="GET" action="{{ route('sales-pocketbook.index') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => request('branch_id'), 'project' => request('project_id'), 'sales' => request('sales_user_id')]))" class="border-2 border-black bg-[#f5f5f5] p-3 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2">
        <input type="hidden" name="tab" value="agenda">
        @if($monitoring)<select class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>@endif
        <select class="sales-input" name="project_id" x-model="project" @change="projectChanged()"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select>
        @if($monitoring)<select class="sales-input" name="sales_user_id" x-model="sales"><option value="">Semua sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select>@endif
        @include('crm.sales-pocketbook._period-picker')
        <button class="sales-button bg-black text-white">Filter</button>
    </form>

    <section class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">{{ $monitoring ? 'Monitoring Agenda' : 'Agenda Saya' }}</div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 p-3">
            @forelse($agendas as $agenda)
            @php
                $needsMissingResult = $agenda->status === 'done' && blank(trim((string) $agenda->activity_result));
            @endphp
            <article class="border-2 border-black bg-[#fffdf2] p-3 shadow-[2px_2px_0_#000]">
                <div class="flex flex-wrap justify-between gap-2"><strong class="font-[Helvetica]">{{ $agenda->title }}</strong><span class="border border-black bg-[#fcc20f] px-2 py-0.5 text-[10px] font-bold uppercase">{{ $agenda->status === 'rescheduled' ? 'Dijadwalkan Ulang' : $agenda->status }}</span></div>
                <div class="mt-1 text-sm">{{ $agenda->scheduled_date->format('d/m/Y') }} · {{ substr($agenda->start_time, 0, 5) }}@if($agenda->end_time) - {{ substr($agenda->end_time, 0, 5) }}@endif · {{ $agenda->duration_minutes }} menit</div>
                <div class="text-sm"><strong>{{ $agenda->sales_activity_category }}</strong> · {{ $agenda->project_name }}@if($agenda->location) · {{ $agenda->location }}@endif</div>
                @if($monitoring)<div class="text-xs">{{ $agenda->branch?->name }} / {{ $agenda->owner?->name }}</div>@endif
                @if($agenda->notes)<p class="mt-2 text-sm italic">{{ $agenda->notes }}</p>@endif
                @if($agenda->activity_result)<div class="mt-2 border-2 border-black bg-green-50 p-2"><strong class="sales-label">Hasil Aktivitas</strong><p class="text-sm whitespace-pre-line">{{ $agenda->activity_result }}</p></div>@endif
                @if(auth()->user()->hasPermission('comments.view'))
                <a href="{{ route('comments.thread', ['alias' => 'sales-agenda', 'id' => $agenda->id]) }}" class="mt-2 inline-block text-xs font-bold text-[#0000ee] underline">Komentar ({{ $agenda->comments_count }})</a>
                @endif
                @if(!in_array($agenda->status, ['done', 'cancelled', 'rescheduled'], true) || $needsMissingResult)
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <form method="POST" action="{{ route('sales-agendas.update', $agenda) }}" class="border border-black bg-white p-2">@csrf @method('PATCH')<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}"><label class="sales-label">Hasil Aktivitas</label><textarea class="sales-input" name="activity_result" rows="2" required></textarea><button class="sales-button bg-[#b7d7a8] mt-2">Tandai Selesai</button></form>
                    @unless($needsMissingResult)
                    <form method="POST" action="{{ route('sales-agendas.reschedule', $agenda) }}" class="border border-black bg-white p-2">@csrf<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}"><label class="sales-label">Jadwal Baru</label><x-crm.date-field name="scheduled_date" required /><div class="grid grid-cols-2 gap-2 mt-2"><x-crm.time-field name="start_time" required /><x-crm.time-field name="end_time" required /></div><button class="sales-button bg-white mt-2">Jadwalkan Ulang</button></form>
                    @endunless
                </div>
                @endif
            </article>
            @empty<div class="lg:col-span-2 p-8 text-center font-['Times_New_Roman']">Belum ada agenda pada periode ini.</div>@endforelse
        </div>
        {{ $agendas->links() }}
    </section>
    @else
    @if($canCreate && $projects->isNotEmpty())
    @php
        $quickProjectId = old('project_id', request('project_id', $defaultProject?->id));
        $quickBranchId = old('branch_id', $defaultProject?->branch_id ?? Auth::user()->branch_id);
        $quickSalesId = old('sales_user_id', Auth::user()->isSales() ? Auth::id() : null);
    @endphp
    <section id="quick-lead-input" class="border-2 border-black bg-white">
        <div class="bg-black text-[#fcc20f] px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">+ Input Lead Hari Ini</div>
        <form method="POST" action="{{ route('sales-leads.store') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => $quickBranchId ?? null, 'project' => $quickProjectId ?? null, 'sales' => $quickSalesId ?? null]))" class="p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
            @csrf
            <div><label class="sales-label">Tanggal Lead</label><x-crm.date-field name="lead_date" :value="old('lead_date', request('lead_date', now()->toDateString()))" required /></div>
            <div><label class="sales-label">Nama Calon Konsumen</label><input class="sales-input" name="customer_name" value="{{ old('customer_name') }}" required></div>
            <div>
                <label class="sales-label">No. WhatsApp / Telepon</label><input class="sales-input" name="phone" value="{{ old('phone') }}" @blur="checkPhone($event.target.value)">
                <div x-show="duplicates.length" x-cloak class="mt-1 border border-[#b8860b] bg-yellow-50 p-2 text-xs"><strong>Peringatan duplikat, tetap dapat disimpan.</strong><template x-for="item in duplicates"><div x-text="`${item.sales} / ${item.branch} / ${item.project} / ${item.date}`"></div></template></div>
            </div>
            <div><label class="sales-label">Sumber Lead</label><select class="sales-input" name="lead_source_id" required><option value="">Pilih sumber</option>@foreach($leadSources as $source)<option value="{{ $source->id }}" @selected(old('lead_source_id') == $source->id)>{{ $source->name }}</option>@endforeach</select></div>
            <div><label class="sales-label">Cabang</label><select class="sales-input" name="branch_id" x-model="branch" required @change="branchChanged()">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
            <div><label class="sales-label">Proyek</label><select class="sales-input" name="project_id" x-model="project" required @change="projectChanged()"><option value="">Pilih proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" data-branch="{{ $project->branch_id }}" @selected($quickProjectId == $project->id) x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></div>
            <div><label class="sales-label">Sales</label><select class="sales-input" name="sales_user_id" x-model="sales" required @disabled(!$monitoring)>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select>@unless($monitoring)<input type="hidden" name="sales_user_id" value="{{ Auth::id() }}">@endunless</div>
            <div><label class="sales-label">Catatan</label><input class="sales-input" name="notes" value="{{ old('notes') }}"></div>
            <div class="xl:col-span-4 flex flex-wrap gap-2 pt-1">
                <button class="sales-button bg-[#fcc20f]" name="submit_action" value="save">Simpan</button>
                <button class="sales-button bg-white" name="submit_action" value="add_another">Simpan & Tambah Lagi</button>
            </div>
        </form>
    </section>
    @endif

    @if($monitoring)
    <form method="GET" action="{{ route('sales-pocketbook.index') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => request('branch_id'), 'project' => request('project_id'), 'sales' => request('sales_user_id')]))" class="border-2 border-black bg-[#f5f5f5] p-3 grid grid-cols-2 md:grid-cols-4 gap-2">
        <select class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
        <select class="sales-input" name="project_id" x-model="project" @change="projectChanged()"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select>
        <select class="sales-input" name="sales_user_id" x-model="sales"><option value="">Semua sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select>
        <select class="sales-input" name="lead_source_id"><option value="">Semua sumber</option>@foreach($leadSources as $source)<option value="{{ $source->id }}" @selected(request('lead_source_id') == $source->id)>{{ $source->name }}</option>@endforeach</select>
        <select class="sales-input" name="stage"><option value="">Semua tahap</option>@foreach(\App\Models\SalesLead::STAGES as $stage => $label)<option value="{{ $stage }}" @selected(request('stage') === $stage)>{{ $label }}</option>@endforeach</select>
        @include('crm.sales-pocketbook._period-picker')
        <button class="sales-button bg-black text-white">Filter</button>
    </form>
    @endif

    <section class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">{{ $monitoring ? 'Monitoring Lead' : 'Lead Saya' }}</div>
        <div class="hidden md:block crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Nama</th><th>Telepon</th><th>Proyek</th>@if($monitoring)<th>Cabang / Sales</th>@endif<th>Sumber</th><th>Tahap</th><th>Progres Cepat</th><th>Aksi</th></tr></thead><tbody>
            @forelse($leads as $lead)<tr data-lead-row="{{ $lead->id }}"><td data-lead-field="date">{{ $lead->lead_date->format('d/m/Y') }}</td><td data-lead-field="name" class="font-bold">{{ $lead->customer_name }}</td><td data-lead-field="phone">{{ $lead->phone ?: '—' }}</td><td data-lead-field="project">{{ $lead->project?->project_name }}</td>@if($monitoring)<td data-lead-field="assignment">{{ $lead->branch?->name }} / {{ $lead->sales?->name }}</td>@endif<td data-lead-field="source">{{ $lead->source_name_snapshot ?: $lead->leadSource?->name }}</td><td><span data-stage-label="{{ $lead->id }}" class="border border-black bg-[#fcc20f] px-2 py-1 text-[10px] font-bold">{{ $lead->currentStageLabel() }}</span></td><td>@can('updateStage', $lead)@include('crm.sales-pocketbook._stage-controls', ['lead' => $lead])@else — @endcan</td><td>@can('update', $lead)<button type="button" @click="openLeadEdit(@js(['id' => $lead->id, 'url' => route('sales-leads.update', $lead), 'fallback_url' => route('sales-leads.edit', $lead), 'token' => app(\App\Services\OptimisticLockService::class)->token($lead), 'branch_id' => (string) $lead->branch_id, 'project_id' => (string) $lead->project_id, 'sales_user_id' => (string) $lead->sales_user_id, 'lead_source_id' => (string) $lead->lead_source_id, 'source_name' => $lead->leadSource?->name ?? $lead->source_name_snapshot, 'source_active' => (bool) $lead->leadSource?->is_active, 'lead_date' => $lead->lead_date->toDateString(), 'customer_name' => $lead->customer_name, 'phone' => $lead->phone, 'notes' => $lead->notes, 'linked_consumer_reference' => $lead->linked_consumer_reference]))" class="font-bold text-[#0000ee] underline">Edit</button>@else — @endcan</td></tr>
            @empty<tr><td colspan="9" class="text-center py-8">Belum ada lead pada periode ini.</td></tr>@endforelse
        </tbody></table></div>
        <div class="md:hidden divide-y-2 divide-black">@forelse($leads as $lead)<article class="p-3" data-lead-card="{{ $lead->id }}"><div class="flex justify-between gap-2"><strong data-lead-field="name">{{ $lead->customer_name }}</strong><span data-lead-field="date" class="text-xs">{{ $lead->lead_date->format('d/m/Y') }}</span></div><div class="text-sm"><span data-lead-field="phone">{{ $lead->phone ?: '—' }}</span> | <span data-lead-field="project">{{ $lead->project?->project_name }}</span></div>@if($monitoring)<div data-lead-field="assignment" class="text-xs">{{ $lead->branch?->name }} / {{ $lead->sales?->name }}</div>@endif<div data-lead-field="source" class="text-xs">{{ $lead->source_name_snapshot ?: $lead->leadSource?->name }}</div><div data-stage-label="{{ $lead->id }}" class="my-2 text-xs font-bold">{{ $lead->currentStageLabel() }}</div>@can('updateStage', $lead)@include('crm.sales-pocketbook._stage-controls', ['lead' => $lead])@endcan @can('update', $lead)<button type="button" @click="openLeadEdit(@js(['id' => $lead->id, 'url' => route('sales-leads.update', $lead), 'fallback_url' => route('sales-leads.edit', $lead), 'token' => app(\App\Services\OptimisticLockService::class)->token($lead), 'branch_id' => (string) $lead->branch_id, 'project_id' => (string) $lead->project_id, 'sales_user_id' => (string) $lead->sales_user_id, 'lead_source_id' => (string) $lead->lead_source_id, 'source_name' => $lead->leadSource?->name ?? $lead->source_name_snapshot, 'source_active' => (bool) $lead->leadSource?->is_active, 'lead_date' => $lead->lead_date->toDateString(), 'customer_name' => $lead->customer_name, 'phone' => $lead->phone, 'notes' => $lead->notes, 'linked_consumer_reference' => $lead->linked_consumer_reference]))" class="mt-2 inline-block font-bold text-[#0000ee] underline">Edit</button>@endcan</article>@empty<div class="p-8 text-center">Belum ada lead pada periode ini.</div>@endforelse</div>
        {{ $leads->links() }}
    </section>
    @endif

    <div x-show="leadModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @keydown.escape.window="if (!conflictDialogOpen()) closeLeadModal()">
        <div x-ref="leadDialog" role="dialog" aria-modal="true" aria-labelledby="lead-dialog-title" class="max-h-[90vh] w-full max-w-3xl overflow-y-auto border-2 border-black bg-white p-4 shadow-[7px_7px_0_#000]" @click.outside="if (!conflictDialogOpen()) closeLeadModal()" @keydown.tab="trapFocus($event, $refs.leadDialog)">
            <div class="mb-3 flex items-center justify-between border-b-2 border-black pb-2"><h2 id="lead-dialog-title" class="font-[Helvetica] text-sm font-bold uppercase">Edit Lead</h2><button type="button" class="text-xl font-bold" aria-label="Tutup dialog edit lead" @click="closeLeadModal()">&times;</button></div>
            <template x-if="leadModalOpen"><div x-data="crmPresence(@js(['enabled' => config('presence.enabled', true), 'heartbeatUrl' => route('presence.heartbeat'), 'indexUrl' => route('presence.index'), 'destroyUrl' => route('presence.destroy'), 'heartbeatSeconds' => config('presence.heartbeat_seconds', 25), 'pageKey' => 'sales-pocketbook', 'recordType' => 'sales_lead', 'mode' => 'editing']))" x-init="updateContext({ branchId: edit.branch_id, recordType: 'sales_lead', recordId: edit.id, mode: 'editing' })" x-show="others.length" class="mb-3 border-2 border-black bg-[#eef1ff] p-2 text-xs"><strong x-text="summary"></strong></div></template>
            <div x-show="leadValidationError" x-text="leadValidationError" class="mb-3 border-2 border-[#c0392b] bg-red-50 p-2 text-sm font-bold" role="alert"></div>
            <form data-conflict-form @submit.prevent="saveLeadEdit($event)" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <input type="hidden" name="expected_updated_at" x-model="edit.token"><input type="hidden" name="presence_session_key">
                <div><label class="sales-label">Tanggal Lead</label><x-crm.date-field name="lead_date" x-ref="leadEditDate" x-model="edit.lead_date" required /></div>
                <div><label class="sales-label">Nama Calon Konsumen</label><input x-ref="leadEditName" class="sales-input" name="customer_name" x-model="edit.customer_name" required></div>
                <div><label class="sales-label">No. WhatsApp / Telepon</label><input class="sales-input" name="phone" x-model="edit.phone"></div>
                <div><label class="sales-label">Sumber Lead</label><select class="sales-input" name="lead_source_id" x-model="edit.lead_source_id" required><template x-if="!edit.source_active"><option :value="edit.lead_source_id" x-text="`${edit.source_name} (nonaktif)`"></option></template>@foreach($leadSources as $source)<option value="{{ $source->id }}">{{ $source->name }}</option>@endforeach</select></div>
                <div><label class="sales-label">Cabang</label><select class="sales-input" name="branch_id" x-model="edit.branch_id" @change="editBranchChanged()" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
                <div><label class="sales-label">Proyek</label><select class="sales-input" name="project_id" x-model="edit.project_id" @change="editProjectChanged()" required>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="editProjectVisible('{{ $project->id }}')" :disabled="!editProjectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></div>
                <div><label class="sales-label">Sales</label><select class="sales-input" name="sales_user_id" x-model="edit.sales_user_id" required @disabled(Auth::user()->isSales())>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="editSalesVisible('{{ $sales->id }}')" :disabled="!editSalesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select></div>
                <div><label class="sales-label">Referensi Konsumen Tertaut</label><input class="sales-input" name="linked_consumer_reference" x-model="edit.linked_consumer_reference"></div>
                <div class="md:col-span-2"><label class="sales-label">Catatan</label><textarea class="sales-input" name="notes" rows="3" x-model="edit.notes"></textarea></div>
                <div class="flex flex-wrap gap-2 md:col-span-2"><button class="sales-button bg-[#fcc20f]" :disabled="leadSaving" x-text="leadSaving ? 'Menyimpan...' : 'Simpan Perubahan'"></button><button type="button" class="sales-button bg-white" @click="closeLeadModal()">Batal</button><a :href="edit.fallback_url" class="sales-button bg-white">Buka Halaman Edit</a></div>
            </form>
        </div>
    </div>

    <div x-show="stageModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @keydown.escape.window="if (!conflictDialogOpen()) closeStageModal()">
        <div x-ref="stageDialog" role="dialog" aria-modal="true" aria-labelledby="stage-dialog-title" class="w-full max-w-sm border-2 border-black bg-white p-4 shadow-[6px_6px_0_#000]" @click.outside="if (!conflictDialogOpen()) closeStageModal()" @keydown.tab="trapFocus($event, $refs.stageDialog)">
            <div class="mb-3 flex justify-between border-b-2 border-black pb-2"><h2 id="stage-dialog-title" class="font-[Helvetica] text-sm font-bold uppercase" x-text="stageEdit.reverse ? 'Batalkan Tahap' : 'Catat Tahap'"></h2><button type="button" class="text-xl font-bold" aria-label="Tutup dialog tahapan lead" @click="closeStageModal()">&times;</button></div>
            <div x-show="stageValidationError" x-text="stageValidationError" class="mb-3 border-2 border-[#c0392b] bg-red-50 p-2 text-sm font-bold" role="alert"></div>
            <form x-ref="stageForm" data-conflict-form @submit.prevent="saveStage()">
                <p class="mb-3 text-sm"><strong x-text="stageEdit.label"></strong><span x-show="stageEdit.current" class="block text-xs" x-text="`Nilai saat ini: ${stageEdit.currentLabel}`"></span></p>
                <template x-if="!stageEdit.reverse"><div class="space-y-3"><div><label class="sales-label">Tanggal</label><x-crm.date-field name="stage_date" x-ref="stageDate" x-model="stageEdit.date" required /></div><div><label class="sales-label">Jam</label><x-crm.time-field name="stage_time" x-ref="stageTime" x-model="stageEdit.time" required /></div><label x-show="stageEdit.current" class="flex items-start gap-2 border-2 border-black bg-yellow-50 p-2 text-xs"><input type="checkbox" x-model="stageEdit.confirmed"> Timpa waktu tahap yang sudah tersimpan.</label></div></template>
                <template x-if="stageEdit.reverse"><label class="flex items-start gap-2 border-2 border-[#c0392b] bg-red-50 p-2 text-xs"><input type="checkbox" x-model="stageEdit.confirmed"> Batalkan tahap ini dan seluruh tahap setelahnya.</label></template>
                <div class="mt-4 flex gap-2"><button class="sales-button bg-[#fcc20f]" :disabled="stageSaving || ((stageEdit.current || stageEdit.reverse) && !stageEdit.confirmed)">Simpan</button><button type="button" class="sales-button bg-white" @click="closeStageModal()">Batal</button></div>
            </form>
        </div>
    </div>
</div>

<style>
    [x-cloak]{display:none!important}.sales-label{display:block;margin-bottom:4px;font:700 11px Helvetica;text-transform:uppercase}.sales-input{width:100%;border:2px solid #000;border-radius:0;background:#fff;padding:8px 10px;font:14px 'Times New Roman'}.sales-button{border:2px solid #000;padding:8px 14px;font:700 11px Helvetica;text-transform:uppercase;box-shadow:2px 2px 0 #000}.stage-button{border:1px solid #000;background:#fff;padding:3px 5px;font:700 9px Helvetica;white-space:nowrap}.stage-button.done{background:#b7d7a8}
</style>
<script>
function salesCascade(projects, salesUsers, initial = {}) {
    return {
        projects, salesUsers,
        branch: String(initial.branch || ''), project: String(initial.project || ''), sales: String(initial.sales || ''),
        projectVisible(id) { return !this.branch || this.projects.find(item => item.id === String(id))?.branch_id === this.branch },
        salesVisible(id) {
            const sales = this.salesUsers.find(item => item.id === String(id))
            if (!sales) return false
            if (this.project) return sales.project_ids.includes(this.project)
            if (this.branch) return sales.project_ids.some(projectId => this.projects.find(item => item.id === projectId)?.branch_id === this.branch)
            return true
        },
        branchChanged() {
            if (this.project && !this.projectVisible(this.project)) this.project = ''
            if (this.sales && !this.salesVisible(this.sales)) this.sales = ''
        },
        projectChanged() {
            const selected = this.projects.find(item => item.id === this.project)
            if (selected) this.branch = selected.branch_id
            if (this.sales && !this.salesVisible(this.sales)) this.sales = ''
        },
    }
}
function salesPocketbook() {
    const projects = @js($cascadeProjects)
    const salesUsers = @js($cascadeSales)
    const localParts = value => {
        const date = value ? new Date(value.replace(' ', 'T')) : new Date()
        return {
            date: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`,
            time: `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`,
        }
    }
    return {
        duplicates: [],
        leadModalOpen: false, leadSaving: false, leadValidationError: '', leadTrigger: null, edit: {}, leadCache: {}, leadTokens: {},
        stageModalOpen: false, stageSaving: false, stageValidationError: '', stageTrigger: null, stageEdit: {},
        async checkPhone(phone) {
            if (!phone) { this.duplicates = []; return }
            try {
                const url = new URL(@json(route('sales-leads.duplicate-phone')), window.location.origin)
                url.searchParams.set('phone', phone)
                const response = await fetch(url, { headers: { Accept: 'application/json' } })
                if (!response.ok) throw new Error()
                this.duplicates = (await response.json()).matches
            } catch (_) {
                this.duplicates = []
                window.oasisToast('Pemeriksaan nomor duplikat belum tersedia. Data tetap dapat disimpan.', 'warning')
            }
        },
        openLeadEdit(lead) {
            this.leadTrigger = document.activeElement
            this.edit = structuredClone(this.leadCache[lead.id] || lead)
            this.edit.token = this.leadTokens[lead.id] || this.edit.token
            this.leadValidationError = ''
            this.leadModalOpen = true
            this.$nextTick(() => {
                this.$refs.leadEditDate?.dispatchEvent(new Event('input', { bubbles: true }))
                this.$refs.leadEditName?.focus()
            })
        },
        closeLeadModal() {
            if (!this.leadModalOpen) return
            this.leadModalOpen = false
            this.$nextTick(() => this.leadTrigger?.focus())
        },
        editProjectVisible(id) { return projects.find(item => item.id === String(id))?.branch_id === String(this.edit.branch_id) },
        editSalesVisible(id) {
            const sales = salesUsers.find(item => item.id === String(id))
            return Boolean(sales && (!this.edit.project_id || sales.project_ids.includes(String(this.edit.project_id))))
        },
        editBranchChanged() {
            if (!this.editProjectVisible(this.edit.project_id)) this.edit.project_id = ''
            if (!this.editSalesVisible(this.edit.sales_user_id)) this.edit.sales_user_id = ''
        },
        editProjectChanged() {
            const selected = projects.find(item => item.id === String(this.edit.project_id))
            if (selected) this.edit.branch_id = selected.branch_id
            if (!this.editSalesVisible(this.edit.sales_user_id)) this.edit.sales_user_id = ''
        },
        conflictDialogOpen() { return document.documentElement.dataset.oasisConflictOpen === '1' },
        async responseData(response) {
            const text = await response.text()
            try { return text ? JSON.parse(text) : {} } catch (_) { return { message: response.ok ? '' : `Server mengembalikan respons tidak valid (${response.status}).` } }
        },
        async saveLeadEdit(event) {
            this.leadSaving = true
            this.leadValidationError = ''
            try {
                const response = await fetch(this.edit.url, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    body: JSON.stringify({
                        branch_id: this.edit.branch_id, project_id: this.edit.project_id, sales_user_id: this.edit.sales_user_id,
                        lead_source_id: this.edit.lead_source_id, lead_date: this.edit.lead_date, customer_name: this.edit.customer_name,
                        phone: this.edit.phone, notes: this.edit.notes, linked_consumer_reference: this.edit.linked_consumer_reference,
                        expected_updated_at: this.edit.token, presence_session_key: event.currentTarget.elements.presence_session_key?.value || null,
                    }),
                })
                const data = await this.responseData(response)
                if (response.status === 409) {
                    window.dispatchEvent(new CustomEvent('oasis-conflict', { detail: { response: data, context: { form: event.currentTarget } } }))
                    return
                }
                if (!response.ok) {
                    const message = Object.values(data.errors || {})[0]?.[0] || data.message || 'Perubahan gagal disimpan.'
                    if (response.status === 422) this.leadValidationError = message
                    else window.oasisToast(message, 'error')
                    return
                }
                this.edit.token = data.updated_at
                this.edit.source_name = data.lead.source
                this.edit.source_active = data.lead.source_active
                this.leadTokens[this.edit.id] = data.updated_at
                this.leadCache[this.edit.id] = structuredClone(this.edit)
                document.querySelectorAll(`[data-lead-id="${this.edit.id}"]`).forEach(group => { group.dataset.token = data.updated_at })
                document.querySelectorAll(`[data-lead-row="${this.edit.id}"], [data-lead-card="${this.edit.id}"]`).forEach(container => {
                    const values = { name: data.lead.customer_name, phone: data.lead.phone || '—', project: data.lead.project, assignment: `${data.lead.branch} / ${data.lead.sales}`, source: data.lead.source, date: data.lead.lead_date.split('-').reverse().join('/') }
                    Object.entries(values).forEach(([field, value]) => container.querySelector(`[data-lead-field="${field}"]`)?.replaceChildren(document.createTextNode(value || '—')))
                })
                document.dispatchEvent(new CustomEvent('oasis-presence-saved'))
                this.closeLeadModal()
                window.oasisToast(data.message || 'Lead berhasil diperbarui.')
            } catch (_) {
                window.oasisToast('Perubahan gagal. Draf tetap tersimpan; periksa koneksi lalu coba lagi.', 'error')
            } finally { this.leadSaving = false }
        },
        stage(event) {
            this.stageTrigger = event.currentTarget
            const button = event.currentTarget
            const controls = button.closest('[data-token]')
            const reverse = button.dataset.reverse === '1'
            const current = button.dataset.current || ''
            const parts = localParts(current)
            this.stageEdit = { button, controls, reverse, current, currentLabel: current ? new Date(current.replace(' ', 'T')).toLocaleString('id-ID') : '', label: button.dataset.label, date: parts.date, time: parts.time, confirmed: false }
            this.stageValidationError = ''
            this.stageModalOpen = true
            this.$nextTick(() => {
                this.$refs.stageDate?.dispatchEvent(new Event('input', { bubbles: true }))
                this.$refs.stageTime?.dispatchEvent(new Event('input', { bubbles: true }))
                this.$refs.stageDialog?.querySelector('.date-display, input, button')?.focus()
            })
        },
        closeStageModal() {
            if (!this.stageModalOpen) return
            this.stageModalOpen = false
            this.$nextTick(() => this.stageTrigger?.focus())
        },
        trapFocus(event, container) {
            const focusable = [...container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter(element => element.offsetParent !== null && !element.matches('input[type="date"], input[type="time"]'))
            if (!focusable.length) return
            const first = focusable[0]
            const last = focusable[focusable.length - 1]
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
        },
        async saveStage() {
            const { button, controls, reverse } = this.stageEdit
            this.stageSaving = true
            this.stageValidationError = ''
            try {
                const response = await fetch(button.dataset.url, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    body: JSON.stringify({ stage: button.dataset.stage, action: reverse ? 'reverse' : 'set', timestamp: reverse ? null : `${this.stageEdit.date} ${this.stageEdit.time}`, reversal_confirmed: reverse ? 1 : null, expected_updated_at: controls.dataset.token }),
                })
                const data = await this.responseData(response)
                if (response.status === 409) {
                    window.dispatchEvent(new CustomEvent('oasis-conflict', { detail: { response: data, context: { form: this.$refs.stageForm } } }))
                    return
                }
                if (!response.ok) {
                    const message = Object.values(data.errors || {})[0]?.[0] || data.message || 'Perubahan gagal.'
                    if (response.status === 422) this.stageValidationError = message
                    else window.oasisToast(message, 'error')
                    return
                }

                document.querySelectorAll(`[data-stage-label="${controls.dataset.leadId}"]`).forEach(el => el.textContent = data.current_stage_label)
                document.querySelectorAll(`[data-lead-id="${controls.dataset.leadId}"]`).forEach(group => {
                    group.dataset.token = data.updated_at
                    group.querySelectorAll('[data-stage-kind="value"]').forEach(stageButton => {
                        const completed = Boolean(data.stages[stageButton.dataset.stage])
                        stageButton.classList.toggle('done', completed)
                        stageButton.title = completed ? new Date(data.stages[stageButton.dataset.stage]).toLocaleString('id-ID') : ''
                        stageButton.dataset.current = data.stages[stageButton.dataset.stage] || ''
                    })
                    group.querySelectorAll('[data-stage-kind="reverse"]').forEach(reverseButton => {
                        reverseButton.classList.toggle('hidden', !data.stages[reverseButton.dataset.stage])
                        reverseButton.dataset.current = data.stages[reverseButton.dataset.stage] || ''
                    })
                })
                this.leadTokens[controls.dataset.leadId] = data.updated_at
                if (this.leadCache[controls.dataset.leadId]) this.leadCache[controls.dataset.leadId].token = data.updated_at
                this.closeStageModal()
                window.oasisToast(data.message || (reverse ? 'Tahap lead berhasil dibatalkan.' : 'Tahap lead berhasil diperbarui.'))
            } catch (_) {
                window.oasisToast('Perubahan gagal. Periksa koneksi lalu coba lagi.', 'error')
            } finally { this.stageSaving = false }
        },
    }
}
</script>
@endsection
