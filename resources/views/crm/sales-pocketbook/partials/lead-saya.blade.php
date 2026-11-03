<section id="lead-saya" class="sales-pocketbook-screen-section sales-lead-workspace" aria-labelledby="lead-saya-title">
    <header class="sales-lead-heading">
        <span>Ruang kerja lead</span>
        <h2 id="lead-saya-title">Lead Saya</h2>
        <p>Pantau lead yang perlu ditindaklanjuti dan yang sudah selesai.</p>
    </header>
    <nav class="sales-lead-status-tabs" aria-label="Status Lead Saya">
        <a href="{{ route('sales-agendas.index', ['tab' => 'leads', 'lead_status' => 'open']) }}" class="{{ $leadStatus === 'open' ? 'is-active' : '' }}">Belum selesai ({{ $openLeadCount }})</a>
        <a href="{{ route('sales-agendas.index', ['tab' => 'leads', 'lead_status' => 'completed']) }}" class="{{ $leadStatus === 'completed' ? 'is-active' : '' }}">Selesai ({{ $completedLeadCount }})</a>
    </nav>

    @if(request('tab') === 'leads' && auth()->user()->can('create', \App\Models\SalesLead::class))
        <details class="sales-lead-entry-panel" @if($errors->any() && request('tab') === 'leads') open @endif>
            <summary><span>+ Tambah lead</span><small>Input cepat lead baru</small></summary>
            <div class="sales-lead-entry-heading">
                <div class="sales-lead-entry-heading-copy">
                    <strong>Tambah lead</strong>
                    <span>Wajib diisi</span>
                </div>
                <button type="button" class="sales-lead-entry-close" aria-label="Tutup form tambah lead" @click="$el.closest('details').open = false; $el.closest('details').querySelector('summary').focus()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            </div>
            @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan.">{{ $errors->first() }}</x-crm.alert>@endif
            @if(session('success'))<x-crm.alert variant="success" title="Berhasil">{{ session('success') }}</x-crm.alert>@endif
            <form method="POST" action="{{ route('sales-leads.store') }}" class="sales-pocketbook-composer sales-lead-entry-form" data-conflict-form x-data="salesLeadSubmit()" @submit="beginSubmit($event)" :aria-busy="submitting">
                @csrf
                <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
                <input type="hidden" name="sales_user_id" value="{{ auth()->id() }}">
                <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
                <div class="sales-lead-entry-scroll">
                    <fieldset class="sales-lead-form-group">
                        <legend>Data lead</legend>
                        @if(auth()->user()->isSales())
                            <input type="hidden" name="lead_date" value="{{ old('lead_date', request('lead_date', today()->toDateString())) }}">
                            <input type="hidden" name="project_id" value="{{ old('project_id', $defaultProjectId) }}">
                            <div class="sales-lead-server-context" aria-label="Konteks lead"><span><b>Tanggal</b>{{ old('lead_date', request('lead_date', today()->toDateString())) }}</span><span><b>Proyek</b>{{ $projects->firstWhere('id', (int) old('project_id', $defaultProjectId))?->project_name ?? 'Proyek utama' }}</span></div>
                        @else
                            <div class="sales-lead-form-grid">
                                <label><span class="sales-lead-label">Tanggal Lead <span class="sales-required-mark" aria-hidden="true">*</span></span><input type="date" name="lead_date" value="{{ old('lead_date', request('lead_date', today()->toDateString())) }}" required></label>
                                <label><span class="sales-lead-label">Proyek <span class="sales-required-mark" aria-hidden="true">*</span></span><select name="project_id" required>@foreach($projects as $item)<option value="{{ $item->id }}" @selected((string) old('project_id', $defaultProjectId) === (string) $item->id)>{{ $item->project_name }}</option>@endforeach</select></label>
                            </div>
                        @endif
                    </fieldset>
                    <fieldset class="sales-lead-form-group">
                        <legend>Data konsumen</legend>
                        <div class="sales-lead-form-grid">
                            <label><span class="sales-lead-label">Nama Calon Konsumen <span class="sales-required-mark" aria-hidden="true">*</span></span><input name="customer_name" value="{{ old('customer_name') }}" autocomplete="name" required></label>
                            <label><span class="sales-lead-label">No. WhatsApp / Telepon <span class="sales-required-mark" aria-hidden="true">*</span></span><input name="phone" value="{{ old('phone') }}" type="tel" autocomplete="tel" required></label>
                        </div>
                    </fieldset>
                    <details class="sales-lead-form-group sales-lead-optional-fields" @if($errors->hasAny(['source', 'platform', 'campaign_name', 'current_status', 'notes']) || old('source') !== null || old('platform') !== null || old('campaign_name') !== null || old('current_status') !== null || old('notes') !== null) open @endif>
                        <summary>Sumber dan proses <span>(opsional untuk input cepat)</span></summary>
                        <div class="sales-lead-form-grid">
                            @foreach(['source' => ['Sumber Lead', \App\Support\SalesLeadMasterData::SOURCES], 'platform' => ['Kanal Masuk', \App\Support\SalesLeadMasterData::CHANNELS], 'campaign_name' => ['Aktivitas Lead', \App\Support\SalesLeadMasterData::ACTIVITIES]] as $name => [$label, $options])
                                <label><span class="sales-lead-label">{{ $label }} @if(!auth()->user()->isSales())<span class="sales-required-mark" aria-hidden="true">*</span>@endif</span><select name="{{ $name }}" @required(!auth()->user()->isSales())><option value="">Pilih {{ strtolower($label) }}</option>@foreach($options as $option)<option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>@endforeach</select></label>
                            @endforeach
                            <label><span class="sales-lead-label">Status Lead <span class="sales-required-mark" aria-hidden="true">*</span></span><select name="current_status" required><option value="no_response">No Respon</option><option value="discussion" @selected(old('current_status') === 'discussion')>Diskusi</option><option value="face_to_face" @selected(old('current_status') === 'face_to_face')>Tatap Muka</option><option value="site_visit" @selected(old('current_status') === 'site_visit')>Cek Lokasi</option></select></label>
                            <label class="sales-lead-notes-field"><span class="sales-lead-label">Catatan</span><textarea name="notes">{{ old('notes') }}</textarea></label>
                        </div>
                    </details>
                </div>
                <div class="sales-lead-form-actions"><button name="submit_action" value="save" class="crm-button crm-button--primary crm-button--md" data-accent="sales" x-bind:disabled="submitting"><span x-show="!submitting">Simpan</span><span x-show="submitting" x-cloak>Menyimpan...</span></button><button name="submit_action" value="add_another" class="crm-button crm-button--secondary crm-button--md" x-bind:disabled="submitting"><span x-show="!submitting">Simpan &amp; Tambah Lagi</span><span x-show="submitting" x-cloak>Menyimpan...</span></button><span class="sr-only" aria-live="polite" x-text="submitting ? 'Lead sedang disimpan.' : ''"></span></div>
            </form>
        </details>
    @endif

    <div class="crm-table-scroll hidden md:block"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Cabang</th><th>Proyek</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($leads as $lead)<tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->branch?->name ?: '-' }}</td><td>{{ $lead->project?->project_name ?: '-' }}</td><td><x-crm.status-badge :variant="$lead->completed_at ? 'success' : 'neutral'">{{ $lead->completed_at ? 'Selesai' : $lead->current_status->label() }}</x-crm.status-badge></td><td><x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)">Detail</x-crm.button>@can('update', $lead)<x-crm.button variant="text" size="sm" :href="route('sales-leads.edit', $lead)">Edit</x-crm.button>@endcan</td></tr>@empty<tr><td colspan="6"><x-crm.empty-state title="Belum ada lead" description="Lead Anda akan tampil di sini." /></td></tr>@endforelse</tbody></table></div>
    <div id="sales-lead-mobile-list" class="sales-lead-mobile-list md:hidden">
        @forelse($leads as $lead)
            <article class="sales-pocketbook-record sales-lead-mobile-card {{ $lead->completed_at ? 'is-completed' : '' }}">
                <div class="sales-lead-mobile-card-header"><div><span>Tanggal lead</span><strong>{{ $lead->lead_date->format('d/m/Y') }}</strong></div><x-crm.status-badge :variant="$lead->completed_at ? 'success' : ($lead->current_status->isManual() ? 'pending' : 'neutral')">{{ $lead->completed_at ? 'Selesai' : $lead->current_status->label() }}</x-crm.status-badge></div>
                <h3>{{ $lead->customer_name }}</h3>
                <dl><div><dt>Proyek</dt><dd>{{ $lead->project?->project_name ?: '-' }}</dd></div><div><dt>Cabang</dt><dd>{{ $lead->branch?->name ?: '-' }}</dd></div></dl>
                <div class="sales-lead-mobile-card-actions flex flex-wrap gap-2"><x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)" class="sales-lead-mobile-detail-action">Lihat detail lead</x-crm.button>@can('update', $lead)<x-crm.button variant="text" size="sm" :href="route('sales-leads.edit', $lead)">Edit lead</x-crm.button>@endcan</div>
            </article>
        @empty
            <x-crm.empty-state title="Belum ada lead" description="Lead Anda akan tampil di sini." />
        @endforelse
    </div>
    @if($leads->total() > 0)
        <x-crm.pagination :collection="$leads" :show-per-page="false" />
    @endif
</section>
