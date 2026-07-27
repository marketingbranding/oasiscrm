@extends('layouts.crm')

@section('title', 'Buku Saku Sales - Oasis CRM')

@section('content')
<div class="space-y-4" x-data="salesPocketbook()">
    <x-crm.page-header color="#fcc20f" title="Buku Saku Sales" />
    <x-crm.page-presence page-key="sales-pocketbook" :branch-id="$selectedBranchId" />

    @if(session('success'))<div class="border-2 border-black bg-green-100 px-4 py-2 font-[Helvetica] text-sm font-bold">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="border-2 border-[#c0392b] bg-red-50 px-4 py-2 text-sm"><strong>Data belum tersimpan.</strong> {{ $errors->first() }}</div>@endif
    @if(session('duplicate_warning'))
        <div class="border-2 border-[#b8860b] bg-yellow-50 p-3 text-sm"><strong>Nomor ini juga ditemukan pada lead lain yang dapat Anda akses:</strong>
            @foreach(session('duplicate_warning') as $match)<div>{{ $match['sales'] }} / {{ $match['branch'] }} / {{ $match['project'] }} / {{ $match['date'] }}</div>@endforeach
        </div>
    @endif

    @if($branches->isEmpty())
        <div class="border-2 border-black bg-[#d77a7a] px-4 py-3 font-['Times_New_Roman'] text-sm">Anda belum memiliki akses cabang.</div>
    @elseif(Auth::user()->hasRole('sales') && $projects->isEmpty())
        <div class="border-2 border-black bg-[#fcc20f] px-4 py-3 font-['Times_New_Roman'] text-sm">Anda belum ditugaskan ke proyek. Hubungi admin pusat.</div>
    @endif

    <div class="flex border-b-2 border-black gap-1">
        @foreach(['leads' => 'Lead', 'agenda' => 'Agenda', 'report' => 'Laporan'] as $key => $label)
            <a href="{{ route('sales-pocketbook.index', ['tab' => $key]) }}" class="border-2 border-b-0 border-black px-4 py-2 font-[Helvetica] text-xs font-bold uppercase {{ $tab === $key ? 'bg-[#fcc20f]' : 'bg-white' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if($tab !== 'leads')
        <div class="border-2 border-black bg-white p-8 text-center font-['Times_New_Roman']">{{ $tab === 'agenda' ? 'Agenda' : 'Laporan' }} akan dilengkapi pada tahap berikutnya.</div>
    @else
    @if($canCreate && $projects->isNotEmpty())
    <section class="border-2 border-black bg-white">
        <div class="bg-black text-[#fcc20f] px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">+ Input Lead Hari Ini</div>
        <form method="POST" action="{{ route('sales-leads.store') }}" class="p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
            @csrf
            @php
                $quickProjectId = old('project_id', request('project_id', $defaultProject?->id));
                $quickBranchId = old('branch_id', $defaultProject?->branch_id ?? Auth::user()->branch_id);
                $quickSalesId = old('sales_user_id', Auth::user()->hasRole('sales') ? Auth::id() : null);
            @endphp
            <div><label class="sales-label">Tanggal Lead</label><x-crm.date-field name="lead_date" :value="old('lead_date', request('lead_date', now()->toDateString()))" required /></div>
            <div><label class="sales-label">Nama Calon Konsumen</label><input class="sales-input" name="customer_name" value="{{ old('customer_name') }}" required></div>
            <div>
                <label class="sales-label">No. WhatsApp / Telepon</label><input class="sales-input" name="phone" value="{{ old('phone') }}" @blur="checkPhone($event.target.value)">
                <div x-show="duplicates.length" x-cloak class="mt-1 border border-[#b8860b] bg-yellow-50 p-2 text-xs"><strong>Peringatan duplikat, tetap dapat disimpan.</strong><template x-for="item in duplicates"><div x-text="`${item.sales} / ${item.branch} / ${item.project} / ${item.date}`"></div></template></div>
            </div>
            <div><label class="sales-label">Sumber Lead</label><select class="sales-input" name="lead_source_id" required><option value="">Pilih sumber</option>@foreach($leadSources as $source)<option value="{{ $source->id }}" @selected(old('lead_source_id') == $source->id)>{{ $source->name }}</option>@endforeach</select></div>
            <div><label class="sales-label">Proyek</label><select class="sales-input" name="project_id" required @change="syncProject($event.target)"><option value="">Pilih proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" data-branch="{{ $project->branch_id }}" @selected($quickProjectId == $project->id)>{{ $project->project_name }}</option>@endforeach</select></div>
            <div><label class="sales-label">Cabang</label><select class="sales-input" name="branch_id" required @change="filterProjects($event.target.value)">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($quickBranchId == $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
            <div><label class="sales-label">Sales</label><select class="sales-input" name="sales_user_id" required @disabled(!$monitoring)>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" data-branch="{{ $sales->branch_id }}" @selected($quickSalesId == $sales->id)>{{ $sales->name }}</option>@endforeach</select>@unless($monitoring)<input type="hidden" name="sales_user_id" value="{{ Auth::id() }}">@endunless</div>
            <div><label class="sales-label">Catatan</label><input class="sales-input" name="notes" value="{{ old('notes') }}"></div>
            <div class="xl:col-span-4 flex flex-wrap gap-2 pt-1">
                <button class="sales-button bg-[#fcc20f]" name="submit_action" value="save">Simpan</button>
                <button class="sales-button bg-white" name="submit_action" value="add_another">Simpan & Tambah Lagi</button>
            </div>
        </form>
    </section>
    @endif

    @if($monitoring)
    <form method="GET" action="{{ route('sales-pocketbook.index') }}" class="border-2 border-black bg-[#f5f5f5] p-3 grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2">
        <select class="sales-input" name="branch_id"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="sales-input" name="project_id"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(request('project_id') == $project->id)>{{ $project->project_name }}</option>@endforeach</select>
        <select class="sales-input" name="sales_user_id"><option value="">Semua sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" @selected(request('sales_user_id') == $sales->id)>{{ $sales->name }}</option>@endforeach</select>
        <select class="sales-input" name="lead_source_id"><option value="">Semua sumber</option>@foreach($leadSources as $source)<option value="{{ $source->id }}" @selected(request('lead_source_id') == $source->id)>{{ $source->name }}</option>@endforeach</select>
        <select class="sales-input" name="stage"><option value="">Semua tahap</option>@foreach(\App\Models\SalesLead::STAGES as $stage => $label)<option value="{{ $stage }}" @selected(request('stage') === $stage)>{{ $label }}</option>@endforeach</select>
        <x-crm.date-field name="date_from" :value="request('date_from')" />
        <x-crm.date-field name="date_to" :value="request('date_to')" />
        <button class="sales-button bg-black text-white">Filter</button>
    </form>
    @endif

    <section class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">{{ $monitoring ? 'Monitoring Lead' : 'Lead Saya' }}</div>
        <div class="hidden md:block crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Nama</th><th>Telepon</th><th>Proyek</th>@if($monitoring)<th>Cabang / Sales</th>@endif<th>Sumber</th><th>Tahap</th><th>Progres Cepat</th><th>Aksi</th></tr></thead><tbody>
            @forelse($leads as $lead)<tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td class="font-bold">{{ $lead->customer_name }}</td><td>{{ $lead->phone ?: '—' }}</td><td>{{ $lead->project?->project_name }}</td>@if($monitoring)<td>{{ $lead->branch?->name }} / {{ $lead->sales?->name }}</td>@endif<td>{{ $lead->source_name_snapshot ?: $lead->leadSource?->name }}</td><td><span data-stage-label="{{ $lead->id }}" class="border border-black bg-[#fcc20f] px-2 py-1 text-[10px] font-bold">{{ $lead->currentStageLabel() }}</span></td><td>@can('updateStage', $lead)@include('crm.sales-pocketbook._stage-controls', ['lead' => $lead])@else — @endcan</td><td>@can('update', $lead)<a href="{{ route('sales-leads.edit', $lead) }}" class="font-bold text-[#0000ee] underline">Edit</a>@else — @endcan</td></tr>
            @empty<tr><td colspan="9" class="text-center py-8">Belum ada lead pada periode ini.</td></tr>@endforelse
        </tbody></table></div>
        <div class="md:hidden divide-y-2 divide-black">@forelse($leads as $lead)<article class="p-3"><div class="flex justify-between gap-2"><strong>{{ $lead->customer_name }}</strong><span class="text-xs">{{ $lead->lead_date->format('d/m/Y') }}</span></div><div class="text-sm">{{ $lead->phone ?: '—' }} | {{ $lead->project?->project_name }}</div>@if($monitoring)<div class="text-xs">{{ $lead->branch?->name }} / {{ $lead->sales?->name }}</div>@endif<div class="text-xs">{{ $lead->source_name_snapshot ?: $lead->leadSource?->name }}</div><div data-stage-label="{{ $lead->id }}" class="my-2 text-xs font-bold">{{ $lead->currentStageLabel() }}</div>@can('updateStage', $lead)@include('crm.sales-pocketbook._stage-controls', ['lead' => $lead])@endcan @can('update', $lead)<a href="{{ route('sales-leads.edit', $lead) }}" class="mt-2 inline-block font-bold text-[#0000ee] underline">Edit</a>@endcan</article>@empty<div class="p-8 text-center">Belum ada lead pada periode ini.</div>@endforelse</div>
        {{ $leads->links() }}
    </section>
    @endif
</div>

<style>
    [x-cloak]{display:none!important}.sales-label{display:block;margin-bottom:4px;font:700 11px Helvetica;text-transform:uppercase}.sales-input{width:100%;border:2px solid #000;border-radius:0;background:#fff;padding:8px 10px;font:14px 'Times New Roman'}.sales-button{border:2px solid #000;padding:8px 14px;font:700 11px Helvetica;text-transform:uppercase;box-shadow:2px 2px 0 #000}.stage-button{border:1px solid #000;background:#fff;padding:3px 5px;font:700 9px Helvetica;white-space:nowrap}.stage-button.done{background:#b7d7a8}
</style>
<script>
function salesPocketbook() {
    return {
        duplicates: [],
        async checkPhone(phone) {
            if (!phone) { this.duplicates = []; return }
            const url = new URL(@json(route('sales-leads.duplicate-phone')), window.location.origin)
            url.searchParams.set('phone', phone)
            const response = await fetch(url, { headers: { Accept: 'application/json' } })
            if (response.ok) this.duplicates = (await response.json()).matches
        },
        syncProject(select) {
            const option = select.options[select.selectedIndex]
            const branch = document.querySelector('[name="branch_id"]')
            if (option?.dataset.branch && branch) branch.value = option.dataset.branch
        },
        filterProjects() {},
        async stage(event) {
            const button = event.currentTarget
            const controls = button.closest('[data-token]')
            const reverse = button.dataset.reverse === '1'
            if (reverse && !confirm('Batalkan tahap ini dan seluruh tahap setelahnya?')) return
            let timestamp = null
            if (!reverse) {
                timestamp = prompt('Waktu tahap (YYYY-MM-DD HH:MM)', new Date().toISOString().slice(0, 16).replace('T', ' '))
                if (!timestamp) return
            }

            button.disabled = true
            try {
                const response = await fetch(button.dataset.url, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    body: JSON.stringify({ stage: button.dataset.stage, action: reverse ? 'reverse' : 'set', timestamp, reversal_confirmed: reverse ? 1 : null, expected_updated_at: controls.dataset.token }),
                })
                const data = await response.json()
                if (!response.ok) {
                    alert(data.message || Object.values(data.errors || {})[0]?.[0] || 'Perubahan gagal.')
                    return
                }

                document.querySelectorAll(`[data-stage-label="${controls.dataset.leadId}"]`).forEach(el => el.textContent = data.current_stage_label)
                document.querySelectorAll(`[data-lead-id="${controls.dataset.leadId}"]`).forEach(group => {
                    group.dataset.token = data.updated_at
                    group.querySelectorAll('[data-stage-kind="value"]').forEach(stageButton => {
                        const completed = Boolean(data.stages[stageButton.dataset.stage])
                        stageButton.classList.toggle('done', completed)
                        stageButton.title = completed ? new Date(data.stages[stageButton.dataset.stage]).toLocaleString('id-ID') : ''
                    })
                    group.querySelectorAll('[data-stage-kind="reverse"]').forEach(reverseButton => {
                        reverseButton.classList.toggle('hidden', !data.stages[reverseButton.dataset.stage])
                    })
                })
            } catch (error) {
                alert('Perubahan gagal. Periksa koneksi lalu coba lagi.')
            } finally {
                button.disabled = false
            }
        },
    }
}
</script>
@endsection
