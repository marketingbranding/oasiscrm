@extends('layouts.crm')

@section('title', 'Buku Saku Saya - Oasis CRM')

@section('content')
<div class="space-y-4">
    @if($dailyReminder['shouldShow']) @include('crm.sales-pocketbook._daily-reminder', ['dailyReminder' => $dailyReminder]) @endif
    <x-crm.page-header variant="canonical" eyebrow="Workspace Pribadi" title="Buku Saku Saya">
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
        <x-crm.section id="lead-saya" title="Lead Saya">
            @if(request('tab') === 'leads' && auth()->user()->can('create', \App\Models\SalesLead::class))
                <div class="sales-lead-entry-heading">
                    <strong>Input Lead Hari Ini</strong>
                    <span>Isi data wajib terlebih dahulu, lalu lengkapi sumber dan proses jika sudah tersedia.</span>
                </div>
                @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>@endif
                @if(session('success'))<x-crm.alert variant="success" title="Berhasil">{{ session('success') }}</x-crm.alert>@endif
                <form method="POST" action="{{ route('sales-leads.store') }}" class="sales-lead-entry-form" data-conflict-form x-data="salesLeadSubmit()" @submit="beginSubmit($event)" :aria-busy="submitting">
                    @csrf
                    <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
                    <input type="hidden" name="sales_user_id" value="{{ auth()->id() }}">
                    <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
                    <fieldset class="sales-lead-form-group">
                        <legend>Data lead</legend>
                        <div class="sales-lead-form-grid">
                            <label>Tanggal Lead<input type="date" name="lead_date" value="{{ old('lead_date', request('lead_date', today()->toDateString())) }}" required></label>
                            <label>Proyek<select name="project_id" required>@foreach($projects as $item)<option value="{{ $item->id }}" @selected((string) old('project_id', $defaultProjectId) === (string) $item->id)>{{ $item->project_name }}</option>@endforeach</select></label>
                        </div>
                    </fieldset>
                    <fieldset class="sales-lead-form-group">
                        <legend>Data konsumen</legend>
                        <div class="sales-lead-form-grid">
                            <label>Nama Calon Konsumen<input name="customer_name" value="{{ old('customer_name') }}" autocomplete="name" required></label>
                            <label>No. WhatsApp / Telepon<input name="phone" value="{{ old('phone') }}" type="tel" autocomplete="tel" required></label>
                        </div>
                    </fieldset>
                    <fieldset class="sales-lead-form-group">
                        <legend>Sumber dan proses</legend>
                        <div class="sales-lead-form-grid">
                            @foreach(['source' => ['Sumber Lead', \App\Support\SalesLeadMasterData::SOURCES], 'platform' => ['Kanal Masuk', \App\Support\SalesLeadMasterData::CHANNELS], 'campaign_name' => ['Aktivitas Lead', \App\Support\SalesLeadMasterData::ACTIVITIES]] as $name => [$label, $options])
                                <label>{{ $label }}<select name="{{ $name }}" required><option value="">Pilih {{ strtolower($label) }}</option>@foreach($options as $option)<option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>@endforeach</select></label>
                            @endforeach
                            <label>Status Lead<select name="current_status" required><option value="no_response">No Respon</option><option value="discussion" @selected(old('current_status') === 'discussion')>Diskusi</option><option value="face_to_face" @selected(old('current_status') === 'face_to_face')>Tatap Muka</option><option value="site_visit" @selected(old('current_status') === 'site_visit')>Cek Lokasi</option></select></label>
                        </div>
                    </fieldset>
                    <fieldset class="sales-lead-form-group">
                        <legend>Catatan tambahan <span>(opsional)</span></legend>
                        <label>Catatan<textarea name="notes">{{ old('notes') }}</textarea></label>
                    </fieldset>
                    <div class="sales-lead-form-actions"><button name="submit_action" value="save" class="crm-button crm-button--primary crm-button--md" data-accent="sales" x-bind:disabled="submitting"><span x-show="!submitting">Simpan</span><span x-show="submitting" x-cloak>Menyimpan...</span></button><button name="submit_action" value="add_another" class="crm-button crm-button--secondary crm-button--md" x-bind:disabled="submitting"><span x-show="!submitting">Simpan &amp; Tambah Lagi</span><span x-show="submitting" x-cloak>Menyimpan...</span></button><span class="sr-only" aria-live="polite" x-text="submitting ? 'Lead sedang disimpan.' : ''"></span></div>
                </form>
            @endif
            <div class="crm-table-scroll hidden md:block"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Cabang</th><th>Proyek</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($leads as $lead)<tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->branch?->name ?: '-' }}</td><td>{{ $lead->project?->project_name ?: '-' }}</td><td><x-crm.status-badge variant="neutral">{{ $lead->current_status->label() }}</x-crm.status-badge></td><td><x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)">Detail</x-crm.button></td></tr>@empty<tr><td colspan="6"><x-crm.empty-state title="Belum ada lead" description="Lead Anda akan tampil di sini." /></td></tr>@endforelse</tbody></table></div>
            <div id="sales-lead-mobile-list" class="sales-lead-mobile-list md:hidden">
                @forelse($leads as $lead)
                    <article class="sales-lead-mobile-card">
                        <div class="sales-lead-mobile-card-header"><div><span>Tanggal lead</span><strong>{{ $lead->lead_date->format('d/m/Y') }}</strong></div><x-crm.status-badge variant="neutral">{{ $lead->current_status->label() }}</x-crm.status-badge></div>
                        <h3>{{ $lead->customer_name }}</h3>
                        <dl><div><dt>Proyek</dt><dd>{{ $lead->project?->project_name ?: '-' }}</dd></div><div><dt>Cabang</dt><dd>{{ $lead->branch?->name ?: '-' }}</dd></div></dl>
                        <x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)">Lihat detail lead</x-crm.button>
                    </article>
                @empty
                    <x-crm.empty-state title="Belum ada lead" description="Lead Anda akan tampil di sini." />
                @endforelse
            </div>
            <x-crm.pagination :collection="$leads" :show-per-page="false" />
        </x-crm.section>
    @else
    @if($errors->any())
        <x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>
    @endif

    @php
        $now = \Carbon\CarbonImmutable::now(config('app.timezone'));
        $previousWorkday = $dailyPlanDate->isMonday() ? $dailyPlanDate->subDays(3) : $dailyPlanDate->subDay();
        $nextWorkday = $dailyPlanDate->isFriday() ? $dailyPlanDate->addDays(3) : $dailyPlanDate->addDay();
        $planActivities = $dailyPlan?->status === 'draft'
            ? $dailyPlan->items->sortBy('position')->values()->map(fn ($item) => ['id' => $item->id, 'sales_activity_category' => $item->sales_activity_category, 'activity' => $item->activity, 'location' => $item->location, 'detail' => $item->detail, 'duration_minutes' => $item->duration_minutes])->all()
            : [];
        $paperItems = $dailyPlan
            ? $dailyPlan->items->sortBy('position')->values()->map(fn ($item) => (object) ['item' => $item, 'state' => app(\App\Services\SalesDailyPlanService::class)->itemState($item, $now)])
            : collect();
        $paperGroups = [
            'ongoing' => ['label' => 'Agenda yang sedang berjalan', 'variant' => 'info'],
            'upcoming' => ['label' => 'Akan datang', 'variant' => 'pending'],
            'overdue' => ['label' => 'Agenda belum selesai', 'variant' => 'danger'],
            'rescheduled' => ['label' => 'Dijadwalkan ulang', 'variant' => 'info'],
            'completed' => ['label' => 'Selesai', 'variant' => 'success'],
        ];
    @endphp
    <x-crm.section id="daily-plan" title="Kertas Agenda Harian" description="Atur agenda untuk hari ini.">
        <div id="sales-agenda-paper" class="sales-agenda-paper" x-data="salesDailyPlan(@js($planActivities))">
            <header class="sales-agenda-paper-header">
                <nav class="sales-agenda-paper-nav" aria-label="Navigasi tanggal kertas agenda">
                    <a class="sales-agenda-paper-nav-link" href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $previousWorkday->toDateString()]) }}" aria-label="Agenda {{ $previousWorkday->locale('id')->translatedFormat('d M Y') }}">Agenda {{ $previousWorkday->locale('id')->translatedFormat('d M Y') }}</a>
                    <span class="sales-agenda-paper-date" aria-live="polite">{{ $dailyPlanDate->locale('id')->translatedFormat('l, d F Y') }}</span>
                    <a class="sales-agenda-paper-nav-link" href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $nextWorkday->toDateString()]) }}" aria-label="Agenda {{ $nextWorkday->locale('id')->translatedFormat('d M Y') }}">Agenda {{ $nextWorkday->locale('id')->translatedFormat('d M Y') }}</a>
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
                                <input type="hidden" :name="`activities[${index}][id]`" x-model="activity.id">
                                <input type="hidden" :name="`activities[${index}][sales_activity_category]`" x-model="activity.sales_activity_category">
                                <input type="hidden" :name="`activities[${index}][activity]`" x-model="activity.activity">
                                <input type="hidden" :name="`activities[${index}][location]`" x-model="activity.location">
                                <input type="hidden" :name="`activities[${index}][detail]`" x-model="activity.detail">
                                <input type="hidden" :name="`activities[${index}][duration_minutes]`" x-model.number="activity.duration_minutes">
                                <div class="sales-agenda-paper-item-main">
                                    <span class="sales-agenda-paper-time" aria-live="polite"><span x-text="schedule(index).start"></span>–<span x-text="schedule(index).end"></span></span>
                                    <span x-show="activity.sales_activity_category" class="sales-agenda-paper-category" x-text="activity.sales_activity_category"></span>
                                    <h3 x-text="activity.activity || 'Agenda baru'"></h3>
                                    <p x-show="activity.location" x-text="activity.location" class="sales-agenda-paper-location"></p>
                                    <p x-show="activity.detail" x-text="activity.detail" class="sales-agenda-paper-detail"></p>
                                </div>
                                <span class="sales-agenda-paper-duration" x-text="activity.duration_minutes + ' mnt'"></span>
                                <button type="button" class="sales-agenda-paper-remove" @click="remove(index)" aria-label="Hapus agenda">&times;</button>
                            </li>
                        </template>
                        <li x-show="activities.length === 0" class="sales-agenda-paper-empty">Agenda masih kosong</li>
                    </ol>
                    <footer class="sales-agenda-paper-footer">
                        <span class="sales-agenda-paper-total">Total <strong x-text="total()"></strong> / 420 menit</span>
                        <div class="sales-agenda-paper-actions">
                            <button type="button" class="sales-agenda-paper-add" @click="openAddModal()">+ Tambah Agenda</button>
                            <x-crm.button type="submit" variant="primary" accent="sales" size="sm" x-bind:disabled="activities.length === 0 || total() === 0">Simpan Draft</x-crm.button>
                        </div>
                    </footer>
                </form>
                @if($dailyPlan)
                    <form method="POST" action="{{ route('sales-daily-plans.submit') }}" class="sales-agenda-paper-submit" @submit.prevent="submitPlan($event)">@csrf<input type="hidden" name="work_date" value="{{ $dailyPlanDate->toDateString() }}"><x-crm.button type="submit" variant="secondary" size="sm" :disabled="$dailyPlan->totalDurationMinutes() !== 420" x-bind:disabled="submitting || activities.length === 0 || total() !== 420" x-bind:aria-busy="submitting">Submit Kertas ({{ $dailyPlan->totalDurationMinutes() }}/420 menit)</x-crm.button></form>
                @endif

                <x-crm.modal name="tambah-agenda" title="Tambah Agenda" description="Tambahkan satu aktivitas ke kertas hari ini. Waktu dihitung otomatis.">
                    <form @submit.prevent="addFromModal()" class="grid gap-3">
                        <x-crm.field label="Kategori Aktivitas" for="tambah-agenda-kategori" required>
                            <select id="tambah-agenda-kategori" x-model="draft.sales_activity_category" class="sales-input" required>
                                <option value="">Pilih kategori</option>
                                @foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)
                                    <option value="{{ $category }}">{{ $category }}</option>
                                @endforeach
                            </select>
                        </x-crm.field>
                        <x-crm.field label="Judul / Aktivitas" for="tambah-agenda-judul" required>
                            <input id="tambah-agenda-judul" x-model="draft.activity" class="sales-input" required>
                        </x-crm.field>
                        <x-crm.field label="Lokasi" for="tambah-agenda-lokasi">
                            <input id="tambah-agenda-lokasi" x-model="draft.location" class="sales-input">
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
                <div class="sales-agenda-paper-total">Total <strong>{{ $dailyPlan->totalDurationMinutes() }}</strong> / 420 menit</div>
                <div class="sales-agenda-paper-locked">Planning sudah dikunci. Anda hanya dapat mengisi hasil dan menyelesaikan aktivitas milik Anda.</div>
                @foreach($paperGroups as $state => $meta)
                    @php
                        $groupItems = $paperItems->where('state', $state);
                    @endphp
                    @if($groupItems->isNotEmpty())
                        <section class="sales-agenda-paper-group" data-state="{{ $state }}" aria-labelledby="paper-group-{{ $state }}">
                            <h3 id="paper-group-{{ $state }}" class="sales-agenda-paper-group-title">{{ $meta['label'] }}</h3>
                            @foreach($groupItems as $entry)
                                <details class="sales-agenda-paper-item {{ $state === 'completed' ? 'is-completed' : '' }}" @if($state !== 'completed') open @endif>
                                    <summary class="sales-agenda-paper-item-summary">
                                        <span class="sales-agenda-paper-index" aria-hidden="true">{{ $loop->iteration }}</span>
                                        <div class="sales-agenda-paper-item-main">
                                            <span class="sales-agenda-paper-time">{{ substr($entry->item->start_time, 0, 5) }}–{{ substr($entry->item->end_time, 0, 5) }}</span>
                                            @if(filled($entry->item->sales_activity_category))<span class="sales-agenda-paper-category">{{ $entry->item->sales_activity_category }}</span>@endif
                                            <h3 class="{{ $state === 'completed' ? 'line-through' : '' }}">{{ $entry->item->activity }}</h3>
                                            @if(filled($entry->item->location))<p class="sales-agenda-paper-location">{{ $entry->item->location }}</p>@endif
                                        </div>
                                        <span class="sales-agenda-paper-duration">{{ $entry->item->duration_minutes }} mnt</span>
                                        <x-crm.status-badge :variant="$meta['variant']">{{ $meta['label'] }}</x-crm.status-badge>
                                    </summary>
                                    <div class="sales-agenda-paper-item-details">
                                        @if(filled($entry->item->detail))<p class="sales-agenda-paper-detail">{{ $entry->item->detail }}</p>@endif
                                        @if(filled($entry->item->activity_result))<p class="sales-agenda-paper-result"><strong>Hasil:</strong> {{ $entry->item->activity_result }}</p>@endif
                                        @if($entry->item->evidence->isNotEmpty())
                                            <div class="sales-agenda-paper-evidence">
                                                <strong>Bukti Foto</strong>
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach($entry->item->evidence as $evidence)
                                                        @if($evidence->purged_at)<span>Bukti foto telah dipindahkan ke arsip.</span>@else<a class="font-bold text-[#0000ee] underline" href="{{ route('sales-daily-plan-items.evidence.show', [$entry->item, $evidence]) }}">{{ $evidence->original_name }}</a>@endif
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                        @if($state === 'rescheduled')
                                            @if($entry->item->rescheduleTo)
                                                <p class="sales-agenda-paper-reschedule-note">Agenda ini dipindahkan ke {{ $entry->item->rescheduleTo->target_work_date->locale('id')->translatedFormat('l, d F Y') }}.</p>
                                                <p class="sales-agenda-paper-detail"><strong>Alasan:</strong> {{ $entry->item->rescheduleTo->reason }}</p>
                                            @endif
                                        @elseif($state !== 'completed')
                                            <div class="sales-agenda-paper-completion-grid">
                                                @if($entry->item->evidence->count() < 2)
                                                    <form method="POST" enctype="multipart/form-data" action="{{ route('sales-daily-plan-items.evidence.store', $entry->item) }}" class="sales-agenda-paper-evidence-form">@csrf<x-crm.field label="Bukti Foto" for="daily-plan-photo-{{ $entry->item->id }}" hint="Maksimal 2 foto JPEG, PNG, atau WebP; masing-masing maksimal 10 MB."><input id="daily-plan-photo-{{ $entry->item->id }}" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="block min-h-11 w-full min-w-0 py-2 text-sm" required></x-crm.field><x-crm.button type="submit" variant="secondary" size="sm">Unggah Bukti Foto</x-crm.button></form>
                                                @else
                                                    <div class="sales-agenda-paper-evidence-form" role="status"><strong>Bukti Foto Lengkap</strong><p>Maksimal 2 foto telah terunggah.</p></div>
                                                @endif
                                                <form method="POST" action="{{ route('sales-daily-plan-items.complete', $entry->item) }}" class="sales-agenda-paper-complete">@csrf @method('PATCH')<x-crm.field label="Hasil Aktivitas" for="daily-plan-result-{{ $entry->item->id }}" required><textarea id="daily-plan-result-{{ $entry->item->id }}" name="activity_result" class="sales-input" rows="2" placeholder="Isi hasil aktivitas" required></textarea></x-crm.field><x-crm.button type="submit" variant="secondary" size="sm">Selesaikan</x-crm.button></form>
                                            </div>
                                            <details class="sales-agenda-paper-reschedule">
                                                <summary>Jadwalkan Ulang</summary>
                                                <form method="POST" action="{{ route('sales-daily-plan-items.reschedule', $entry->item) }}" class="sales-agenda-paper-reschedule-form">
                                                    @csrf
                                                    <x-crm.field label="Alasan penjadwalan ulang" for="daily-plan-reschedule-reason-{{ $entry->item->id }}" hint="Agenda akan dipindahkan ke draft hari kerja berikutnya yang masih tersedia.">
                                                        <textarea id="daily-plan-reschedule-reason-{{ $entry->item->id }}" name="reason" class="sales-input" rows="2" maxlength="1000" required></textarea>
                                                    </x-crm.field>
                                                    <x-crm.button type="submit" variant="secondary" size="sm">Jadwalkan Ulang ke Hari Kerja Berikutnya</x-crm.button>
                                                </form>
                                            </details>
                                        @endif
                                    </div>
                                </details>
                            @endforeach
                        </section>
                    @endif
                @endforeach
            @endif
        </div>
    </x-crm.section>

    @if(!$project)
        <x-crm.alert variant="warning" title="Penugasan proyek diperlukan">Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek utama.</x-crm.alert>
    @endif

    @endif
</div>
@endsection
