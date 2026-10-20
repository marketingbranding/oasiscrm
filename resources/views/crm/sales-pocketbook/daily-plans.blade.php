@extends('layouts.crm')

@section('title', 'Daily Planning - Buku Saku Sales')

@section('content')
<div class="space-y-5">
    <x-crm.page-header variant="canonical" eyebrow="BUKU SAKU SALES" title="Daily Planning" description="Susun Agenda Sales dalam 420 menit kerja efektif.">
        <x-slot:actions>
            @if(auth()->user()->isSales())
                <form method="POST" action="{{ route('sales-daily-plans.store') }}" class="flex items-end gap-2">
                    @csrf
                    <label class="text-xs font-bold uppercase">Tanggal
                        <input type="date" name="plan_date" value="{{ $date->toDateString() }}" class="sales-input mt-1" required>
                    </label>
                    <x-crm.button type="submit" variant="primary" accent="sales">Buat Daily Plan</x-crm.button>
                </form>
            @endif
        </x-slot:actions>
    </x-crm.page-header>

    <form method="GET" class="flex flex-wrap items-end gap-2 border-2 border-black bg-white p-3">
        <label class="text-xs font-bold uppercase">Tanggal
            <input type="date" name="date" value="{{ $date->toDateString() }}" class="sales-input mt-1" required>
        </label>
        <x-crm.button type="submit" variant="secondary">Lihat</x-crm.button>
    </form>

    @if($canViewTargets)
    <x-crm.section id="daily-plan-targets" title="Target Lead Bulanan" description="Pencapaian dihitung dari lead milik Sales berdasarkan tanggal lead.">
        <div class="grid gap-3 md:grid-cols-3">
            @forelse($summary as $row)
                <x-crm.card padding="sm">
                    <strong>{{ $row->sales->name }}</strong>
                    <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                        <div><dt class="text-xs uppercase">Target</dt><dd class="font-bold">{{ $row->target === null ? 'Belum ditetapkan' : number_format($row->target) }}</dd></div>
                        <div><dt class="text-xs uppercase">Lead Aktual</dt><dd class="font-bold">{{ number_format($row->actual) }}</dd></div>
                        <div><dt class="text-xs uppercase">Achievement</dt><dd class="font-bold">{{ $row->achievement === null ? 'N/A' : $row->achievement.'%' }}</dd></div>
                    </dl>
                </x-crm.card>
            @empty
                <x-crm.empty-state title="Belum ada Sales dalam cakupan" />
            @endforelse
        </div>
        @if($canManageTargets)
            <details class="mt-4 border border-gray-400 p-3">
                <summary class="cursor-pointer font-bold">Atur target (Superadmin)</summary>
                <form method="POST" action="{{ route('sales-daily-plans.targets.store') }}" class="mt-3 grid gap-2 md:grid-cols-4">
                    @csrf
                    <select name="sales_user_id" class="sales-input" required><option value="">Pilih Sales</option>@foreach(app(\App\Services\SalesLeadTargetService::class)->visibleSales(auth()->user()) as $sales)<option value="{{ $sales->id }}">{{ $sales->name }}</option>@endforeach</select>
                    <input type="number" name="period_year" value="{{ $date->year }}" class="sales-input" required>
                    <input type="number" name="period_month" value="{{ $date->month }}" min="1" max="12" class="sales-input" required>
                    <input type="number" name="lead_target" min="0" class="sales-input" placeholder="Target lead" required>
                    <div class="md:col-span-4"><x-crm.button type="submit" variant="primary">Simpan Target</x-crm.button></div>
                </form>
            </details>
        @endif
    </x-crm.section>
    @endif

    @if($plans->isNotEmpty())
        <x-crm.section id="daily-plan-workspace" title="Daily Plan {{ $date->format('d/m/Y') }}" description="Jam mulai dan selesai dibuat otomatis dari urutan dan durasi efektif.">
            @if(!$selectedPlan || auth()->user()->isSales() || $plans->count() === 1 || request()->filled('sales_id'))
                @php($plan = $selectedPlan)
                @if($plan)
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <x-crm.card padding="sm"><span class="text-xs uppercase">Sales</span><strong class="block">{{ $plan->sales?->name }}</strong></x-crm.card>
                        <x-crm.card padding="sm"><span class="text-xs uppercase">Status</span><strong class="block">{{ ucfirst($plan->status) }}</strong></x-crm.card>
                        <x-crm.card padding="sm"><span class="text-xs uppercase">Efektif</span><strong class="block">{{ $schedule['total_minutes'] }} / 420 menit</strong></x-crm.card>
                        <x-crm.card padding="sm"><span class="text-xs uppercase">Sisa</span><strong class="block">{{ $schedule['remaining_minutes'] }} menit</strong></x-crm.card>
                    </div>
                    @if($errors->any())<x-crm.alert variant="error" title="Perubahan belum tersimpan.">{{ $errors->first() }}</x-crm.alert>@endif

                    @php($agendaIds = $plan->agendas->pluck('id')->values()->all())
                    <div class="mt-4 crm-table-scroll">
                        <table class="crm-data-table">
                            <thead><tr><th>Urutan</th><th>Waktu</th><th>Kategori</th><th>Durasi</th><th>Detail Planning</th><th>Hasil Aktivitas</th><th>Aksi</th></tr></thead>
                            <tbody>
                                @forelse($plan->agendas as $agenda)
                                    <tr>
                                        <td>{{ $agenda->sort_order }}</td>
                                        <td>{{ substr((string) $agenda->start_time, 0, 5) }}–{{ substr((string) $agenda->end_time, 0, 5) }}</td>
                                        <td>{{ $agenda->sales_activity_category }}</td>
                                        <td>{{ $agenda->duration_minutes }} menit efektif</td>
                                        <td>{{ $agenda->task_detail }}</td>
                                        <td>{{ $agenda->activity_result ?: '-' }}</td>
                                        <td>
                                            @if($plan->status === 'draft' && auth()->user()->can('update', $plan))
                                                <details>
                                                    <summary class="cursor-pointer font-bold text-[#0000ee] underline">Edit</summary>
                                                    <form method="POST" action="{{ route('sales-daily-plans.agendas.update', [$plan, $agenda]) }}" class="mt-2 grid gap-1">
                                                        @csrf @method('PATCH')
                                                        <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($plan) }}">
                                                        <select name="sales_activity_category" class="sales-input" aria-label="Kategori Agenda">@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option @selected($category === $agenda->sales_activity_category)>{{ $category }}</option>@endforeach</select>
                                                        <input type="number" name="duration_minutes" value="{{ $agenda->duration_minutes }}" min="1" max="420" class="sales-input" aria-label="Durasi efektif">
                                                        <textarea name="task_detail" class="sales-input" aria-label="Detail Planning" required>{{ $agenda->task_detail }}</textarea>
                                                        <input name="title" value="{{ $agenda->title }}" class="sales-input" aria-label="Judul Agenda">
                                                        <button class="font-bold text-[#0000ee] underline">Simpan</button>
                                                    </form>
                                                </details>
                                                <form method="POST" action="{{ route('sales-daily-plans.agendas.destroy', [$plan, $agenda]) }}">
                                                    @csrf @method('DELETE')
                                                    <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($plan) }}">
                                                    <button class="font-bold text-red-700 underline" onclick="return confirm('Hapus Agenda ini?')">Hapus</button>
                                                </form>
                                            @elseif($plan->status === 'submitted' && auth()->user()->isSales() && (int) $agenda->owner_user_id === (int) auth()->id())
                                                <a class="font-bold text-[#0000ee] underline" href="{{ route('sales-agendas.index', ['tab' => 'agenda']) }}">Isi hasil</a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7">Belum ada Agenda planning.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($plan->status === 'draft' && auth()->user()->can('update', $plan) && count($agendaIds) > 1)
                        <div class="mt-3 flex flex-wrap items-center gap-2 border border-gray-400 bg-white p-3">
                            <span class="text-xs font-bold uppercase">Ubah urutan</span>
                            @foreach($agendaIds as $agendaIndex => $agendaId)
                                @if($agendaIndex > 0)
                                    @php($upIds = $agendaIds)
                                    @php([$upIds[$agendaIndex - 1], $upIds[$agendaIndex]] = [$upIds[$agendaIndex], $upIds[$agendaIndex - 1]])
                                    <form method="POST" action="{{ route('sales-daily-plans.agendas.reorder', $plan) }}">
                                        @csrf
                                        @foreach($upIds as $upId)<input type="hidden" name="agenda_ids[]" value="{{ $upId }}">@endforeach
                                        <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($plan) }}">
                                        <button type="submit" class="border border-black px-2 py-1 font-bold" aria-label="Naikkan Agenda urutan {{ $agendaIndex + 1 }}">↑ {{ $agendaIndex + 1 }}</button>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if($plan->status === 'draft' && auth()->user()->can('update', $plan))
                        <form method="POST" action="{{ route('sales-daily-plans.agendas.store', $plan) }}" class="mt-4 grid gap-3 border-2 border-black bg-white p-3 md:grid-cols-4">
                            @csrf
                            <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($plan) }}">
                            <label class="text-xs font-bold uppercase">Kategori<select name="sales_activity_category" class="sales-input mt-1" required><option value="">Pilih kategori</option>@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option>{{ $category }}</option>@endforeach</select></label>
                            <label class="text-xs font-bold uppercase">Durasi efektif<input type="number" name="duration_minutes" min="1" max="420" class="sales-input mt-1" required></label>
                            <label class="text-xs font-bold uppercase md:col-span-2">Detail Planning<textarea name="task_detail" class="sales-input mt-1" required></textarea></label>
                            <div class="md:col-span-4"><x-crm.button type="submit" variant="primary" accent="sales">Tambah Agenda</x-crm.button></div>
                        </form>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('sales-daily-plans.submit', $plan) }}">@csrf<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($plan) }}"><x-crm.button type="submit" variant="primary" :disabled="$schedule['total_minutes'] !== 420">Kirim Daily Plan</x-crm.button></form>
                            <span class="self-center text-sm">Submit hanya tersedia saat total tepat 420 menit.</span>
                        </div>
                    @elseif($plan->status === 'submitted' && auth()->user()->can('update', $plan))
                        <div class="mt-4 border-2 border-black bg-white p-3">
                            <h3 class="font-bold">Koreksi planning oleh Coordinator</h3>
                            <p class="mt-1 text-sm">Perubahan tetap harus menghasilkan total efektif tepat 420 menit.</p>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @foreach($plan->agendas as $agenda)
                                    <form method="POST" action="{{ route('sales-daily-plans.agendas.update', [$plan, $agenda]) }}" class="grid gap-2 border border-gray-400 p-3">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($plan) }}">
                                        <strong>{{ $agenda->title }}</strong>
                                        <select name="sales_activity_category" class="sales-input" aria-label="Kategori {{ $agenda->title }}">@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option @selected($category === $agenda->sales_activity_category)>{{ $category }}</option>@endforeach</select>
                                        <input type="number" name="duration_minutes" value="{{ $agenda->duration_minutes }}" min="1" max="420" class="sales-input" aria-label="Durasi {{ $agenda->title }}">
                                        <textarea name="task_detail" class="sales-input" aria-label="Detail {{ $agenda->title }}" required>{{ $agenda->task_detail }}</textarea>
                                        <input name="title" value="{{ $agenda->title }}" class="sales-input" aria-label="Judul {{ $agenda->title }}">
                                        <button class="font-bold text-[#0000ee] underline">Simpan koreksi</button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            @else
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach($plans as $item)
                        <a class="border-2 border-black bg-white p-3 hover:bg-[#fcc20f]" href="{{ route('sales-daily-plans.index', ['date' => $date->toDateString(), 'sales_id' => $item->sales_user_id]) }}"><strong>{{ $item->sales?->name }}</strong><span class="block text-sm">{{ ucfirst($item->status) }} · {{ $item->agendas->sum('duration_minutes') }} menit</span></a>
                    @endforeach
                </div>
            @endif
        </x-crm.section>
    @else
        <x-crm.empty-state title="Belum ada Daily Plan" description="Sales dapat membuat Daily Plan untuk tanggal kerja yang dipilih." />
    @endif

    <x-crm.section id="historical-sales-agendas" title="Agenda Historis Tanpa Daily Plan" description="Agenda lama tetap terlihat dan tidak dikenai validasi Daily Plan baru.">
        <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Sales</th><th>Agenda</th><th>Waktu Lama</th><th>Durasi</th><th>Detail</th></tr></thead><tbody>@forelse($historicalAgendas as $agenda)<tr><td>{{ $agenda->scheduled_date?->format('d/m/Y') }}</td><td>{{ $agenda->owner?->name ?: '-' }}</td><td>{{ $agenda->title }}</td><td>{{ $agenda->start_time ?: '-' }}–{{ $agenda->end_time ?: '-' }}</td><td>{{ $agenda->duration_minutes === null ? '-' : $agenda->duration_minutes.' menit' }}</td><td>{{ $agenda->task_detail ?: '-' }}</td></tr>@empty<tr><td colspan="6">Tidak ada Agenda historis pada tanggal ini.</td></tr>@endforelse</tbody></table></div>
    </x-crm.section>
</div>
@endsection
