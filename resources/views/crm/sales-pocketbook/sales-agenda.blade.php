@extends('layouts.crm')

@section('title', 'Agenda Saya - Oasis CRM')

@section('content')
@php
    $canCleanup = auth()->user()->hasPrimaryRole('superadmin') || (app(\App\Services\ImpersonationService::class)->isActive(request()) && app(\App\Services\ImpersonationService::class)->originalUser(request())?->isSuperadmin());
@endphp
<div class="space-y-4">
    @if($dailyReminder['shouldShow']) @include('crm.sales-pocketbook._daily-reminder', ['dailyReminder' => $dailyReminder]) @endif
    <x-crm.page-header variant="canonical" eyebrow="Workspace Pribadi" title="Agenda Saya" description="Catat dan selesaikan agenda sales Anda.">
        <x-slot:actions>
            @if($canExport)<x-crm.button variant="secondary" :href="route('sales-agendas.export')">Export XLSX</x-crm.button>@endif
        </x-slot:actions>
    </x-crm.page-header>

    <nav class="sales-pocketbook-tabs crm-horizontal-tabs" aria-label="Workspace pribadi Sales">
        <a href="{{ route('sales-agendas.index') }}" class="sales-pocketbook-tab {{ $tab === 'agenda' ? 'active' : '' }}" @if($tab === 'agenda') aria-current="page" @endif>Agenda</a>
        <a href="{{ route('sales-agendas.index', ['tab' => 'leads']) }}" class="sales-pocketbook-tab {{ $tab === 'leads' ? 'active' : '' }}" @if($tab === 'leads') aria-current="page" @endif>Lead Saya</a>
    </nav>

    <div class="sales-pocketbook-scope" aria-label="Konteks agenda aktif">
        <div><span>Sales</span><strong>{{ Auth::user()->name }}</strong></div>
        <div><span>Cabang</span><strong>{{ $project?->branch?->name ?? 'Belum tersedia' }}</strong></div>
        <div><span>Proyek</span><strong>{{ $project?->project_name ?? 'Belum tersedia' }}</strong></div>
    </div>

    @if($tab === 'leads')
        <x-crm.section id="lead-saya" title="Lead Saya" description="Lead milik Anda dalam cakupan aktif.">
            @if(request('tab') === 'leads' && auth()->user()->can('create', \App\Models\SalesLead::class))
                <div class="mb-3 border-b-2 border-black bg-black px-3 py-2 text-xs font-bold uppercase text-[#fcc20f]">Input Lead Hari Ini</div>
                @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>@endif
                @if(session('success'))<x-crm.alert variant="success" title="Berhasil">{{ session('success') }}</x-crm.alert>@endif
                <form method="POST" action="{{ route('sales-leads.store') }}" class="mb-4 grid gap-3 border-2 border-black bg-white p-4 md:grid-cols-2" data-conflict-form>
                    @csrf
                    <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
                    <input type="hidden" name="sales_user_id" value="{{ auth()->id() }}">
                    <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
                    <label class="text-xs font-bold uppercase">Tanggal Lead<input type="date" name="lead_date" value="{{ old('lead_date', request('lead_date', today()->toDateString())) }}" class="mt-1 block w-full border border-gray-300 px-3 py-2" required></label>
                    <label class="text-xs font-bold uppercase">Proyek<select name="project_id" class="mt-1 block w-full border border-gray-300 px-3 py-2" required>@foreach($projects as $item)<option value="{{ $item->id }}" @selected((string) old('project_id', $defaultProjectId) === (string) $item->id)>{{ $item->project_name }}</option>@endforeach</select></label>
                    @foreach(['customer_name' => 'Nama Calon Konsumen', 'phone' => 'No. WhatsApp / Telepon'] as $name => $label)<label class="text-xs font-bold uppercase">{{ $label }}<input name="{{ $name }}" value="{{ old($name) }}" class="mt-1 block w-full border border-gray-300 px-3 py-2" required></label>@endforeach
                    @foreach(['source' => ['Sumber Lead', \App\Support\SalesLeadMasterData::SOURCES], 'platform' => ['Kanal Masuk', \App\Support\SalesLeadMasterData::CHANNELS], 'campaign_name' => ['Aktivitas Lead', \App\Support\SalesLeadMasterData::ACTIVITIES]] as $name => [$label, $options])<label class="text-xs font-bold uppercase">{{ $label }}<select name="{{ $name }}" class="mt-1 block w-full border border-gray-300 px-3 py-2" required><option value="">Pilih {{ strtolower($label) }}</option>@foreach($options as $option)<option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>@endforeach</select></label>@endforeach
                    <label class="text-xs font-bold uppercase">Status Lead<select name="current_status" class="mt-1 block w-full border border-gray-300 px-3 py-2" required><option value="no_response">No Respon</option><option value="discussion" @selected(old('current_status') === 'discussion')>Diskusi</option><option value="face_to_face" @selected(old('current_status') === 'face_to_face')>Tatap Muka</option><option value="site_visit" @selected(old('current_status') === 'site_visit')>Cek Lokasi</option></select></label>
                    <label class="text-xs font-bold uppercase md:col-span-2">Catatan<textarea name="notes" class="mt-1 block w-full border border-gray-300 px-3 py-2">{{ old('notes') }}</textarea></label>
                    <div class="flex gap-2 md:col-span-2"><button name="submit_action" value="save" class="border-2 border-black bg-[#fcc20f] px-4 py-2 font-bold">Simpan</button><button name="submit_action" value="add_another" class="border border-gray-400 bg-white px-4 py-2 font-bold">Simpan &amp; Tambah Lagi</button></div>
                </form>
            @endif
            <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Cabang</th><th>Proyek</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($leads as $lead)<tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->branch?->name ?: '-' }}</td><td>{{ $lead->project?->project_name ?: '-' }}</td><td><x-crm.status-badge variant="neutral">{{ $lead->current_status->label() }}</x-crm.status-badge></td><td><x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)">Detail</x-crm.button></td></tr>@empty<tr><td colspan="6"><x-crm.empty-state title="Belum ada lead" description="Lead Anda akan tampil di sini." /></td></tr>@endforelse</tbody></table></div>
            <x-crm.pagination :collection="$leads" :show-per-page="false" />
        </x-crm.section>
    @else
    @if($errors->any())
        <x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>
    @endif

    @php
        $now = \Carbon\CarbonImmutable::now(config('app.timezone'));
        $planActivities = $dailyPlan?->status === 'draft'
            ? $dailyPlan->items->sortBy('position')->values()->map(fn ($item) => ['id' => $item->id, 'activity' => $item->activity, 'detail' => $item->detail, 'duration_minutes' => $item->duration_minutes])->all()
            : [];
        $paperItems = $dailyPlan
            ? $dailyPlan->items->sortBy('position')->values()->map(fn ($item) => (object) ['item' => $item, 'state' => app(\App\Services\SalesDailyPlanService::class)->itemState($item, $now)])
            : collect();
        $paperGroups = [
            'ongoing' => ['label' => 'Agenda yang sedang berjalan', 'variant' => 'info'],
            'upcoming' => ['label' => 'Akan datang', 'variant' => 'pending'],
            'overdue' => ['label' => 'Terlewat', 'variant' => 'danger'],
            'completed' => ['label' => 'Selesai', 'variant' => 'success'],
        ];
    @endphp
    <x-crm.section id="daily-plan" title="Kertas Agenda Harian" description="Satu kertas untuk satu hari kerja. Tambah agenda melalui tombol + Tambah Agenda; jam dihitung otomatis melewati istirahat 12.00–13.00.">
        <div id="sales-agenda-paper" class="sales-agenda-paper" x-data="salesDailyPlan(@js($planActivities))">
            <header class="sales-agenda-paper-header">
                <nav class="sales-agenda-paper-nav" aria-label="Navigasi tanggal kertas agenda">
                    <a class="sales-agenda-paper-nav-link" href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $dailyPlanDate->copy()->subDay()->toDateString()]) }}" aria-label="Hari sebelumnya">&larr; Kemarin</a>
                    <span class="sales-agenda-paper-date" aria-live="polite">{{ $dailyPlanDate->locale('id')->translatedFormat('l, d F Y') }}</span>
                    <a class="sales-agenda-paper-nav-link" href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $dailyPlanDate->copy()->addDay()->toDateString()]) }}" aria-label="Hari berikutnya">Besok &rarr;</a>
                </nav>
                <div class="sales-agenda-paper-status">
                    @if($dailyPlan?->status === 'submitted')<x-crm.status-badge variant="success">Terkunci setelah submit</x-crm.status-badge>@elseif($dailyPlan)<x-crm.status-badge variant="pending">Draft</x-crm.status-badge>@else<x-crm.status-badge variant="neutral">Belum dibuat</x-crm.status-badge>@endif
                </div>
            </header>

            @if($dailyPlan?->status !== 'submitted')
                <form method="POST" action="{{ route('sales-daily-plans.store') }}" class="sales-agenda-paper-form">
                    @csrf
                    <input type="hidden" name="work_date" value="{{ $dailyPlanDate->toDateString() }}">
                    <ol class="sales-agenda-paper-list">
                        <template x-for="(activity, index) in activities" :key="index">
                            <li class="sales-agenda-paper-item">
                                <span class="sales-agenda-paper-index" x-text="index + 1" aria-hidden="true"></span>
                                <input type="hidden" :name="`activities[${index}][activity]`" x-model="activity.activity">
                                <input type="hidden" :name="`activities[${index}][detail]`" x-model="activity.detail">
                                <input type="hidden" :name="`activities[${index}][duration_minutes]`" x-model.number="activity.duration_minutes">
                                <div class="sales-agenda-paper-item-main">
                                    <span class="sales-agenda-paper-time" aria-live="polite"><span x-text="schedule(index).start"></span>–<span x-text="schedule(index).end"></span></span>
                                    <h3 x-text="activity.activity || 'Agenda baru'"></h3>
                                    <p x-show="activity.detail" x-text="activity.detail" class="sales-agenda-paper-detail"></p>
                                </div>
                                <span class="sales-agenda-paper-duration" x-text="activity.duration_minutes + ' mnt'"></span>
                                <button type="button" class="sales-agenda-paper-remove" @click="remove(index)" aria-label="Hapus agenda">&times;</button>
                            </li>
                        </template>
                        <li x-show="activities.length === 0" class="sales-agenda-paper-empty">Kertas ini masih kosong. Tambahkan agenda pertama Anda.</li>
                    </ol>
                    <footer class="sales-agenda-paper-footer">
                        <span class="sales-agenda-paper-total">Total <strong x-text="total()"></strong> / 420 menit</span>
                        <div class="sales-agenda-paper-actions">
                            <button type="button" class="sales-agenda-paper-add" @click="openAddModal()">+ Tambah Agenda</button>
                            <x-crm.button type="submit" variant="primary" accent="sales" size="sm">Simpan Draft</x-crm.button>
                        </div>
                    </footer>
                </form>
                @if($dailyPlan)
                    <form method="POST" action="{{ route('sales-daily-plans.submit') }}" class="sales-agenda-paper-submit">@csrf<input type="hidden" name="work_date" value="{{ $dailyPlanDate->toDateString() }}"><x-crm.button type="submit" variant="secondary" size="sm">Submit Kertas ({{ $dailyPlan->totalDurationMinutes() }}/420 menit)</x-crm.button></form>
                @endif

                <x-crm.modal name="tambah-agenda" title="Tambah Agenda" description="Tambahkan satu aktivitas ke kertas hari ini. Waktu dihitung otomatis.">
                    <form @submit.prevent="addFromModal()" class="grid gap-3">
                        <x-crm.field label="Judul Agenda" for="tambah-agenda-judul" required>
                            <input id="tambah-agenda-judul" x-model="draft.activity" class="sales-input" required>
                        </x-crm.field>
                        <x-crm.field label="Detail" for="tambah-agenda-detail">
                            <textarea id="tambah-agenda-detail" x-model="draft.detail" class="sales-input" rows="2"></textarea>
                        </x-crm.field>
                        <x-crm.field label="Durasi (menit)" for="tambah-agenda-durasi" required>
                            <input id="tambah-agenda-durasi" type="number" min="1" max="420" x-model.number="draft.duration_minutes" class="sales-input" required>
                        </x-crm.field>
                        <div class="sales-agenda-paper-preview" aria-live="polite">Waktu otomatis: <strong><span x-text="draftSchedule().start"></span>–<span x-text="draftSchedule().end"></span></strong></div>
                        <p x-show="modalError" x-text="modalError" class="sales-agenda-paper-modal-error" role="alert"></p>
                        <div class="flex justify-end gap-2"><x-crm.button type="submit" variant="primary" accent="sales" size="sm">Tambah ke Kertas</x-crm.button></div>
                    </form>
                </x-crm.modal>
            @else
                <div class="sales-agenda-paper-locked">Planning sudah dikunci. Anda hanya dapat mengisi hasil dan menyelesaikan aktivitas milik Anda.</div>
                @foreach($paperGroups as $state => $meta)
                    @php
                        $groupItems = $paperItems->where('state', $state);
                    @endphp
                    @if($groupItems->isNotEmpty())
                        <section class="sales-agenda-paper-group" data-state="{{ $state }}" aria-labelledby="paper-group-{{ $state }}">
                            <h3 id="paper-group-{{ $state }}" class="sales-agenda-paper-group-title">{{ $meta['label'] }}</h3>
                            @foreach($groupItems as $entry)
                                <article class="sales-agenda-paper-item {{ $state === 'completed' ? 'is-completed' : '' }}">
                                    <span class="sales-agenda-paper-index" aria-hidden="true">{{ $loop->iteration }}</span>
                                    <div class="sales-agenda-paper-item-main">
                                        <span class="sales-agenda-paper-time">{{ substr($entry->item->start_time, 0, 5) }}–{{ substr($entry->item->end_time, 0, 5) }}</span>
                                        <h3 class="{{ $state === 'completed' ? 'line-through' : '' }}">{{ $entry->item->activity }}</h3>
                                        @if(filled($entry->item->detail))<p class="sales-agenda-paper-detail">{{ $entry->item->detail }}</p>@endif
                                        @if($state === 'completed' && filled($entry->item->activity_result))<p class="sales-agenda-paper-result"><strong>Hasil:</strong> {{ $entry->item->activity_result }}</p>@endif
                                    </div>
                                    <span class="sales-agenda-paper-duration">{{ $entry->item->duration_minutes }} mnt</span>
                                    <x-crm.status-badge :variant="$meta['variant']">{{ $meta['label'] }}</x-crm.status-badge>
                                    @if($state !== 'completed')
                                        <form method="POST" action="{{ route('sales-daily-plan-items.complete', $entry->item) }}" class="sales-agenda-paper-complete">@csrf @method('PATCH')<input name="activity_result" class="sales-input" placeholder="Isi hasil aktivitas" required><x-crm.button type="submit" variant="secondary" size="sm">Selesaikan</x-crm.button></form>
                                    @endif
                                </article>
                            @endforeach
                        </section>
                    @endif
                @endforeach
            @endif
        </div>
    </x-crm.section>

    @if(!$project)
        <x-crm.alert variant="warning" title="Penugasan proyek diperlukan">Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek utama.</x-crm.alert>
    @else
        <x-crm.section id="agenda-baru" title="Agenda Baru">
            <form method="POST" enctype="multipart/form-data" action="{{ route('sales-agendas.store') }}" class="grid gap-3 md:grid-cols-2">
                @csrf
                <x-crm.field label="Tanggal Agenda" for="scheduled_date" required :error="$errors->first('scheduled_date')">
                    <x-crm.date-field id="scheduled_date" name="scheduled_date" :value="old('scheduled_date', now()->toDateString())" required />
                </x-crm.field>
                <x-crm.field label="Kategori Aktivitas" for="sales_activity_category" required :error="$errors->first('sales_activity_category')">
                    <select id="sales_activity_category" name="sales_activity_category" class="sales-input" required>
                        <option value="">Pilih kategori</option>
                        @foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)
                            <option value="{{ $category }}" @selected(old('sales_activity_category') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </x-crm.field>
                <x-crm.field label="Judul Agenda" for="title" required :error="$errors->first('title')">
                    <input id="title" name="title" value="{{ old('title') }}" class="sales-input" required>
                </x-crm.field>
                <x-crm.field label="Lokasi" for="location" :error="$errors->first('location')">
                    <input id="location" name="location" value="{{ old('location') }}" class="sales-input">
                </x-crm.field>
                <x-crm.field label="Hasil Aktivitas" for="activity_result" :error="$errors->first('activity_result')">
                    <textarea id="activity_result" name="activity_result" class="sales-input" rows="2">{{ old('activity_result') }}</textarea>
                </x-crm.field>
                <x-crm.field label="Bukti Foto" for="photos" hint="Opsional. Maksimal 2 foto JPEG, PNG, atau WebP; masing-masing maksimal 10 MB." :error="$errors->first('photos') ?: $errors->first('photos.*')">
                    <input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" class="block min-h-11 w-full min-w-0 py-2 text-sm" multiple>
                </x-crm.field>
                <div class="md:col-span-2"><x-crm.button type="submit" variant="primary" accent="sales">Simpan Agenda</x-crm.button></div>
            </form>
        </x-crm.section>
    @endif

    <x-crm.section id="agenda-saya" title="Daftar Agenda Saya">
        @if($canCleanup)
            <p class="mb-3 text-sm">Superadmin: hapus agenda kanonik beserta semua bukti lokal hanya jika belum masuk arsip.</p>
        @endif
        <div class="sales-agenda-monitor">
            <div class="sales-agenda-list">
                @forelse($agendas as $agenda)
                    <article class="sales-agenda-item">
                        <div class="sales-agenda-identity">
                            <div><span class="sales-agenda-kicker">{{ $agenda->sales_activity_category ?: 'Aktivitas Sales' }}</span><h3>{{ $agenda->title }}</h3></div>
                            <x-crm.status-badge :status="$agenda->status">{{ ucfirst($agenda->status) }}</x-crm.status-badge>
                        </div>
                        <dl class="sales-agenda-facts">
                            <div><dt>Tanggal</dt><dd>{{ $agenda->scheduled_date->format('d/m/Y') }}</dd></div>
                            <div><dt>Lokasi</dt><dd>{{ $agenda->location ?: 'Tidak dicatat' }}</dd></div>
                        </dl>
                        @if(filled($agenda->activity_result))<div class="sales-agenda-result"><strong>Hasil Aktivitas</strong><p class="whitespace-pre-line">{{ $agenda->activity_result }}</p></div>@endif
                        @if($agenda->evidence->isNotEmpty())
                            <div class="sales-agenda-notes">
                                <strong>Bukti Foto</strong>
                                <div class="flex flex-wrap gap-3">
                                    @foreach($agenda->evidence as $evidence)
                                        @if($evidence->purged_at)<span>Bukti foto telah dipindahkan ke arsip.</span>
                                        @else
                                            <span class="flex items-center gap-2"><a class="font-bold text-[#0000ee] underline" href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}">Foto {{ $loop->iteration }}</a>@if(app(\App\Services\SalesAgendaEvidenceAuthorizationService::class)->canMutate(auth()->user(), $agenda))<form method="POST" action="{{ route('sales-agendas.evidence.destroy', [$agenda, $evidence]) }}">@csrf @method('DELETE')<button type="submit" class="font-bold text-red-700 underline" onclick="return confirm('Hapus bukti foto ini?')">Hapus</button></form>@endif</span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if(app(\App\Services\SalesAgendaEvidenceAuthorizationService::class)->canMutate(auth()->user(), $agenda))
                            <div class="sales-agenda-action-grid">
                                @if($agenda->evidence->count() < 2)
                                    <form method="POST" enctype="multipart/form-data" action="{{ route('sales-agendas.evidence.store', $agenda) }}" class="sales-agenda-action-form">
                                        @csrf
                                        <x-crm.field label="Bukti Foto" for="photo-{{ $agenda->id }}" hint="Opsional untuk penyelesaian agenda. Maksimal 2 foto JPEG, PNG, atau WebP; masing-masing maksimal 10 MB.">
                                            <input id="photo-{{ $agenda->id }}" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="block w-full min-w-0 text-sm" required>
                                        </x-crm.field>
                                        <x-crm.button type="submit" variant="secondary" size="sm">Unggah Foto</x-crm.button>
                                    </form>
                                @else
                                    <div class="sales-agenda-action-form" role="status"><strong class="font-[Helvetica] text-xs uppercase">Bukti Foto Lengkap</strong><p>Maksimal 2 foto telah terunggah.</p></div>
                                @endif
                                <form method="POST" action="{{ route('sales-agendas.update', $agenda) }}" class="sales-agenda-action-form">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}">
                                    <x-crm.field label="Hasil Aktivitas" for="result-{{ $agenda->id }}" required><input id="result-{{ $agenda->id }}" name="activity_result" class="sales-input" required></x-crm.field>
                                    <x-crm.button type="submit" variant="secondary" size="sm">Selesaikan</x-crm.button>
                                </form>
                            </div>
                        @endif
                        @if($canCleanup)<footer class="sales-agenda-footer"><x-crm.sales-agenda-cleanup :agenda="$agenda" :can-cleanup="$canCleanup" /></footer>@endif
                    </article>
                @empty
                    <x-crm.empty-state title="Belum ada agenda">Agenda Anda akan tampil di sini.</x-crm.empty-state>
                @endforelse
            </div>
        </div>
        <x-crm.pagination :collection="$agendas" :show-per-page="false" />
    </x-crm.section>
    @endif
</div>
@endsection
