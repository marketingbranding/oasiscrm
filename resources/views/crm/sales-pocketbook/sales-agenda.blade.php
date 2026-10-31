@extends('layouts.crm')

@section('title', 'Buku Saku Saya - Oasis CRM')

@section('content')
<div class="sales-pocketbook-workspace space-y-4">
    @if($dailyReminder['shouldShow']) @include('crm.sales-pocketbook._daily-reminder', ['dailyReminder' => $dailyReminder]) @endif
    <x-crm.page-header variant="canonical" eyebrow="Workspace Pribadi" title="Buku Saku Saya">
        <x-slot:actions>
            <form method="POST" action="{{ route('sales-pocketbook.onboarding.replay') }}">
                @csrf
                <button type="submit" class="sales-pocketbook-help-link">Lihat panduan Buku Saku</button>
            </form>
            @if($canExport)<x-crm.button variant="secondary" :href="route('sales-agendas.export')">Export XLSX</x-crm.button>@endif
        </x-slot:actions>
    </x-crm.page-header>

    <nav class="sales-pocketbook-tabs crm-horizontal-tabs" aria-label="Workspace pribadi Sales">
        <a href="{{ route('sales-agendas.index') }}" class="sales-pocketbook-tab {{ $tab === 'agenda' ? 'active' : '' }}" @if($tab === 'agenda') aria-current="page" @endif>Agenda</a>
        <a href="{{ route('sales-agendas.index', ['tab' => 'leads']) }}" class="sales-pocketbook-tab {{ $tab === 'leads' ? 'active' : '' }}" @if($tab === 'leads') aria-current="page" @endif>Lead Saya</a>
    </nav>

    <div class="sales-pocketbook-context-strip sales-pocketbook-scope" aria-label="Konteks agenda aktif">
        <div><span>Sales</span><strong>{{ Auth::user()->name }}</strong></div>
        <div><span>Cabang</span><strong>{{ $project?->branch?->name ?? 'Belum tersedia' }}</strong></div>
        <div><span>Proyek</span><strong>{{ $project?->project_name ?? 'Belum tersedia' }}</strong></div>
    </div>

    @if($tab === 'leads')
        <x-crm.section id="lead-saya" title="Lead Saya" class="sales-pocketbook-screen-section">
            <nav class="sales-lead-status-tabs" aria-label="Status Lead Saya">
                <a href="{{ route('sales-agendas.index', ['tab' => 'leads', 'lead_status' => 'open']) }}" class="{{ $leadStatus === 'open' ? 'is-active' : '' }}">Belum selesai ({{ $openLeadCount }})</a>
                <a href="{{ route('sales-agendas.index', ['tab' => 'leads', 'lead_status' => 'completed']) }}" class="{{ $leadStatus === 'completed' ? 'is-active' : '' }}">Selesai ({{ $completedLeadCount }})</a>
            </nav>
            @if(request('tab') === 'leads' && auth()->user()->can('create', \App\Models\SalesLead::class))
                <div class="sales-lead-entry-heading">
                    <strong>Input Lead Hari Ini</strong>
                    <span>Isi data wajib terlebih dahulu, lalu lengkapi sumber dan proses jika sudah tersedia.</span>
                </div>
                @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>@endif
                @if(session('success'))<x-crm.alert variant="success" title="Berhasil">{{ session('success') }}</x-crm.alert>@endif
                    <form method="POST" action="{{ route('sales-leads.store') }}" class="sales-pocketbook-composer sales-lead-entry-form" data-conflict-form x-data="salesLeadSubmit()" @submit="beginSubmit($event)" :aria-busy="submitting">
                    @csrf
                    <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
                    <input type="hidden" name="sales_user_id" value="{{ auth()->id() }}">
                    <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
                    <fieldset class="sales-lead-form-group">
                        <legend>Data lead</legend>
                        @if(auth()->user()->isSales())
                            <input type="hidden" name="lead_date" value="{{ old('lead_date', request('lead_date', today()->toDateString())) }}">
                            <input type="hidden" name="project_id" value="{{ old('project_id', $defaultProjectId) }}">
                            <p class="sales-lead-server-context">Tanggal {{ old('lead_date', request('lead_date', today()->toDateString())) }} · {{ $projects->firstWhere('id', (int) old('project_id', $defaultProjectId))?->project_name ?? 'Proyek utama' }}</p>
                        @else
                            <div class="sales-lead-form-grid">
                                <label>Tanggal Lead<input type="date" name="lead_date" value="{{ old('lead_date', request('lead_date', today()->toDateString())) }}" required></label>
                                <label>Proyek<select name="project_id" required>@foreach($projects as $item)<option value="{{ $item->id }}" @selected((string) old('project_id', $defaultProjectId) === (string) $item->id)>{{ $item->project_name }}</option>@endforeach</select></label>
                            </div>
                        @endif
                    </fieldset>
                    <fieldset class="sales-lead-form-group">
                        <legend>Data konsumen</legend>
                        <div class="sales-lead-form-grid">
                            <label>Nama Calon Konsumen<input name="customer_name" value="{{ old('customer_name') }}" autocomplete="name" required></label>
                            <label>No. WhatsApp / Telepon<input name="phone" value="{{ old('phone') }}" type="tel" autocomplete="tel" required></label>
                        </div>
                    </fieldset>
                    <details class="sales-lead-form-group sales-lead-optional-fields" @if(!auth()->user()->isSales()) open @endif>
                        <summary>Sumber dan proses <span>(opsional untuk input cepat)</span></summary>
                        <div class="sales-lead-form-grid">
                            @foreach(['source' => ['Sumber Lead', \App\Support\SalesLeadMasterData::SOURCES], 'platform' => ['Kanal Masuk', \App\Support\SalesLeadMasterData::CHANNELS], 'campaign_name' => ['Aktivitas Lead', \App\Support\SalesLeadMasterData::ACTIVITIES]] as $name => [$label, $options])
                                <label>{{ $label }}<select name="{{ $name }}" @required(!auth()->user()->isSales())><option value="">Pilih {{ strtolower($label) }}</option>@foreach($options as $option)<option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>@endforeach</select></label>
                            @endforeach
                            <label>Status Lead<select name="current_status" required><option value="no_response">No Respon</option><option value="discussion" @selected(old('current_status') === 'discussion')>Diskusi</option><option value="face_to_face" @selected(old('current_status') === 'face_to_face')>Tatap Muka</option><option value="site_visit" @selected(old('current_status') === 'site_visit')>Cek Lokasi</option></select></label>
                        </div>
                    </details>
                    <fieldset class="sales-lead-form-group">
                        <legend>Catatan tambahan <span>(opsional)</span></legend>
                        <label>Catatan<textarea name="notes">{{ old('notes') }}</textarea></label>
                    </fieldset>
                    <div class="sales-lead-form-actions"><button name="submit_action" value="save" class="crm-button crm-button--primary crm-button--md" data-accent="sales" x-bind:disabled="submitting"><span x-show="!submitting">Simpan</span><span x-show="submitting" x-cloak>Menyimpan...</span></button><button name="submit_action" value="add_another" class="crm-button crm-button--secondary crm-button--md" x-bind:disabled="submitting"><span x-show="!submitting">Simpan &amp; Tambah Lagi</span><span x-show="submitting" x-cloak>Menyimpan...</span></button><span class="sr-only" aria-live="polite" x-text="submitting ? 'Lead sedang disimpan.' : ''"></span></div>
                </form>
            @endif
             <div class="crm-table-scroll hidden md:block"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Cabang</th><th>Proyek</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($leads as $lead)<tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->branch?->name ?: '-' }}</td><td>{{ $lead->project?->project_name ?: '-' }}</td><td><x-crm.status-badge :variant="$lead->completed_at ? 'success' : 'neutral'">{{ $lead->completed_at ? 'Selesai' : $lead->current_status->label() }}</x-crm.status-badge></td><td><x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)">Detail</x-crm.button>@can('update', $lead)<x-crm.button variant="text" size="sm" :href="route('sales-leads.edit', $lead)">Edit</x-crm.button>@endcan</td></tr>@empty<tr><td colspan="6"><x-crm.empty-state title="Belum ada lead" description="Lead Anda akan tampil di sini." /></td></tr>@endforelse</tbody></table></div>
            <div id="sales-lead-mobile-list" class="sales-lead-mobile-list md:hidden">
                @forelse($leads as $lead)
                    <article class="sales-pocketbook-record sales-lead-mobile-card {{ $lead->completed_at ? 'is-completed' : '' }}">
                        <div class="sales-lead-mobile-card-header"><div><span>Tanggal lead</span><strong>{{ $lead->lead_date->format('d/m/Y') }}</strong></div><x-crm.status-badge :variant="$lead->completed_at ? 'success' : 'neutral'">{{ $lead->completed_at ? 'Selesai' : $lead->current_status->label() }}</x-crm.status-badge></div>
                        <h3>{{ $lead->customer_name }}</h3>
                        <dl><div><dt>Proyek</dt><dd>{{ $lead->project?->project_name ?: '-' }}</dd></div><div><dt>Cabang</dt><dd>{{ $lead->branch?->name ?: '-' }}</dd></div></dl>
                         <div class="flex flex-wrap gap-2"><x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)">Lihat detail lead</x-crm.button>@can('update', $lead)<x-crm.button variant="text" size="sm" :href="route('sales-leads.edit', $lead)">Edit lead</x-crm.button>@endcan</div>
                    </article>
                @empty
                    <x-crm.empty-state title="Belum ada lead" description="Lead Anda akan tampil di sini." />
                @endforelse
            </div>
            <x-crm.pagination :collection="$leads" :show-per-page="false" />
        </x-crm.section>
    @else
    @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>@endif

    @php
        $previousAgendaDate = $agendaDate->subDay();
        $nextAgendaDate = $agendaDate->addDay();
        $agendaStatuses = [
            'planned' => ['Direncanakan', 'pending'],
            'confirmed' => ['Dikonfirmasi', 'info'],
            'done' => ['Selesai', 'success'],
            'cancelled' => ['Dibatalkan', 'inactive'],
            'rescheduled' => ['Dijadwalkan Ulang', 'warning'],
        ];
    @endphp
    <x-crm.section id="agenda-input" title="Tambah Agenda" description="Catat satu aktivitas Sales pada tanggal yang dipilih." class="sales-pocketbook-screen-section sales-pocketbook-composer">
        @if($project)
            <form method="POST" action="{{ route('sales-agendas.store') }}" class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">
                @csrf
                <x-crm.field label="Tanggal" for="sales-agenda-date" required><x-crm.date-field id="sales-agenda-date" name="scheduled_date" :value="$agendaDate->toDateString()" required /></x-crm.field>
                <x-crm.field label="Kategori" for="sales-agenda-category" required><select id="sales-agenda-category" class="sales-input" name="sales_activity_category" required><option value="">Pilih kategori</option>@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option value="{{ $category }}" @selected(old('sales_activity_category') === $category)>{{ $category }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Judul Agenda" for="sales-agenda-title" required><input id="sales-agenda-title" class="sales-input" name="title" value="{{ old('title') }}" required></x-crm.field>
                <x-crm.field label="Lokasi" for="sales-agenda-location"><input id="sales-agenda-location" class="sales-input" name="location" value="{{ old('location') }}"></x-crm.field>
                <x-crm.field label="Hasil awal" for="sales-agenda-result" hint="Opsional; isi setelah kegiatan bila belum tersedia." class="md:col-span-2"><textarea id="sales-agenda-result" class="sales-input" name="activity_result" rows="2">{{ old('activity_result') }}</textarea></x-crm.field>
                <div class="flex items-end xl:col-span-2"><x-crm.button type="submit" variant="primary" accent="sales">Simpan Agenda</x-crm.button></div>
            </form>
        @else
            <x-crm.alert variant="warning" title="Penugasan proyek diperlukan">Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek sebelum membuat agenda.</x-crm.alert>
        @endif
    </x-crm.section>

    <x-crm.section id="agenda-list" title="Agenda Saya" :description="$agendaDate->locale('id')->translatedFormat('l, d F Y')" class="sales-pocketbook-screen-section">
        <div id="sales-agenda-paper" class="sales-agenda-paper" data-sales-agenda-paper>
            <header class="sales-agenda-paper-header">
                <nav class="sales-agenda-paper-nav" aria-label="Navigasi tanggal agenda">
                    <a class="sales-agenda-paper-nav-link" href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $previousAgendaDate->toDateString()]) }}">Agenda {{ $previousAgendaDate->locale('id')->translatedFormat('d M Y') }}</a>
                    <span class="sales-agenda-paper-date" aria-live="polite">{{ $agendaDate->locale('id')->translatedFormat('l, d F Y') }}</span>
                    <a class="sales-agenda-paper-nav-link" href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $nextAgendaDate->toDateString()]) }}">Agenda {{ $nextAgendaDate->locale('id')->translatedFormat('d M Y') }}</a>
                </nav>
                <x-crm.status-badge variant="info">Agenda Buku Saku</x-crm.status-badge>
            </header>

            <div class="sales-agenda-paper-form">
                <div class="sales-agenda-day-switcher" aria-label="Konteks agenda">
                    @foreach($agendaGroups as $key => $group)
                        <a href="#agenda-{{ $key }}" class="sales-agenda-day-switcher-link {{ $key === 'today' ? 'is-active' : '' }}">
                            <span>{{ $group['label'] }}</span><strong>{{ $group['items']->count() }}</strong>
                        </a>
                    @endforeach
                </div>
                @foreach($agendaGroups as $key => $group)
                    <section id="agenda-{{ $key }}" class="sales-agenda-paper-group" aria-labelledby="agenda-{{ $key }}-title">
                        <header class="sales-agenda-paper-group-title" id="agenda-{{ $key }}-title">
                            <span>{{ $group['label'] }}</span>
                            <span>{{ $group['items']->count() }} agenda</span>
                        </header>
                        <ol class="sales-agenda-paper-list">
                            @forelse($group['items'] as $agenda)
                                @php
                                    [$agendaStatusLabel, $agendaStatusVariant] = $agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral'];
                                @endphp
                                <li class="sales-agenda-paper-item {{ $agenda->status === 'done' ? 'is-completed' : '' }}" data-agenda-paper-item>
                                    <span class="sales-agenda-paper-index" aria-hidden="true">{{ $loop->iteration }}</span>
                                    <div class="sales-agenda-paper-item-main">
                                        <span class="sales-agenda-paper-time">{{ $agenda->scheduled_date?->format('d M') }} · {{ $agenda->start_time ? substr((string) $agenda->start_time, 0, 5) : 'Waktu fleksibel' }}{{ $agenda->end_time ? '–'.substr((string) $agenda->end_time, 0, 5) : '' }}</span>
                                        @if(filled($agenda->sales_activity_category))<span class="sales-agenda-paper-category">{{ $agenda->sales_activity_category }}</span>@endif
                                        <h3>{{ $agenda->title }}</h3>
                                        @if(filled($agenda->location))<p class="sales-agenda-paper-location">{{ $agenda->location }}</p>@endif
                                        @if($agenda->evidence->isNotEmpty())
                                            <div class="sales-agenda-paper-evidence">
                                                <strong>Bukti Foto</strong>
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach($agenda->evidence as $evidence)
                                                        @if($evidence->purged_at)<span>Bukti foto telah diarsipkan.</span>@else<a class="font-bold text-[#0000ee] underline" href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}">{{ $evidence->original_name }}</a>@endif
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                    @if($agenda->duration_minutes)<span class="sales-agenda-paper-duration">{{ $agenda->duration_minutes }} mnt</span>@endif
                                    <x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge>
                                    <a class="sales-agenda-paper-detail-link" href="{{ route('sales-agendas.show', $agenda) }}">Buka agenda</a>
                                </li>
                            @empty
                                <li class="sales-agenda-paper-empty"><x-crm.empty-state title="Belum ada agenda" description="Agenda pada konteks ini akan tampil di sini." /></li>
                            @endforelse
                        </ol>
                    </section>
                @endforeach
                <footer class="sales-agenda-paper-footer">
                    <span class="sales-agenda-paper-total">Agenda tersimpan langsung di Buku Saku Sales</span>
                    <span class="text-sm">Buka agenda untuk checklist, foto, dan hasil aktivitas.</span>
                </footer>
            </div>
        </div>
        <x-crm.pagination :collection="$agendas" :show-per-page="false" />
    </x-crm.section>

    @endif
</div>
@endsection
