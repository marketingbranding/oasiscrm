@php
    $status = $lead->current_status;
    $latestVisit = $lead->siteVisits->first();
    $normalConsumer = $lead->consumerLinks->first(fn ($link) => $link->sheet_type === 'data_konsumen' && $link->status === 'completed');
    $nupConsumer = $lead->consumerLinks->first(fn ($link) => $link->sheet_type === 'data_konsumen_nup' && $link->status === 'completed');
    $activeSlik = $lead->slikAttempts->firstWhere('status', 'submitted');
    $latestSlik = $lead->slikAttempts->first();
    $freelance = $lead->freelanceLinks->firstWhere('status', 'completed');
    $akad = $lead->akadLinks->firstWhere('status', 'completed');
    $autoOpenSiteVisit = request('lifecycle_action') === 'site_visit' && request()->integer('lead') === $lead->id;
    $sheetCapabilities = $lifecycleCapabilitiesByBranch->get($lead->branch_id, []);
    $siteVisitAvailable = ($sheetCapabilities['data_ceklok'] ?? false) === true;
    $consumerSheet = $lead->project?->is_nup_eligible ? 'data_konsumen_nup' : 'data_konsumen';
    $consumerAvailable = ($sheetCapabilities[$consumerSheet] ?? false) === true;
    $slikAvailable = ($sheetCapabilities['bi_checking'] ?? false) === true;
    $freelanceAvailable = ($sheetCapabilities['data_sales'] ?? false) === true;
    $statusVariant = match ($status) {
        \App\Enums\SalesLeadStatus::Akad => 'success',
        \App\Enums\SalesLeadStatus::SlikRejected => 'danger',
        \App\Enums\SalesLeadStatus::NoResponse => 'pending',
        default => 'processing',
    };
@endphp

<section class="mt-3 border-t border-black/20 pt-3" aria-label="Siklus lead {{ $lead->customer_name }}">
    <div class="flex flex-wrap items-center gap-2">
        <x-crm.status-badge :variant="$statusVariant">{{ $status->label() }}</x-crm.status-badge>
        <span class="text-xs text-gray-600">{{ $lead->current_status_source ? 'Sumber: '.str_replace('_', ' ', $lead->current_status_source) : 'Status awal' }}{{ $lead->current_status_changed_at ? ' / '.$lead->current_status_changed_at->format('d/m/Y H:i') : '' }}</span>
        <x-crm.status-badge :variant="$lead->external_sync_id ? 'success' : (filled($lead->branch?->sheet_id) ? 'warning' : 'inactive')">
            {{ $lead->external_sync_id ? 'Tersinkron UUID' : (filled($lead->branch?->sheet_id) ? 'Lokal / belum tersinkron' : 'Spreadsheet belum dikonfigurasi') }}
        </x-crm.status-badge>
    </div>

    <dl class="mt-2 grid grid-cols-1 gap-1 text-xs sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="font-bold">Cek lokasi</dt><dd>{{ $latestVisit ? ($latestVisit->is_completed ? 'Lengkap, '.$latestVisit->visit_date?->format('d/m/Y') : 'Belum lengkap / Isi Nanti') : 'Belum dicatat' }}</dd></div>
        <div><dt class="font-bold">Konsumen / NUP</dt><dd>{{ $normalConsumer ? 'Konsumen tercatat' : ($nupConsumer ? 'NUP tercatat, belum UTJ' : 'Belum dikonversi') }}</dd></div>
        <div><dt class="font-bold">SLIK</dt><dd>{{ $latestSlik ? ($latestSlik->status === 'rejected' ? 'Ditolak: '.$latestSlik->slik_result : 'Diajukan') : 'Belum diajukan' }}</dd></div>
        <div><dt class="font-bold">Freelance / Akad</dt><dd>{{ $freelance ? 'Freelance selesai' : 'Bukan freelance' }} / {{ $akad ? 'Akad tercatat' : 'Belum Akad' }}</dd></div>
    </dl>

    @can('updateLifecycleStatus', $lead)
        <div class="mt-3 flex flex-wrap gap-2" aria-label="Tindakan siklus lead">
            @if($status->isManual())
                @foreach([\App\Enums\SalesLeadStatus::NoResponse, \App\Enums\SalesLeadStatus::Discussion] as $manualStatus)
                    @if($status !== $manualStatus)
                    <form method="POST" action="{{ route('sales-leads.lifecycle-status.update', $lead) }}" x-data="{ submitting: false }" @submit="submitting = true">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="{{ $manualStatus->value }}">
                        <x-crm.button type="submit" variant="secondary" size="sm" x-bind:disabled="submitting">{{ $manualStatus->label() }}</x-crm.button>
                    </form>
                    @endif
                @endforeach
            @else
                <span class="text-xs font-bold">Status sistem bersifat baca-saja.</span>
            @endif
            @can('recordSiteVisit', $lead) @if($siteVisitAvailable)<x-crm.button type="button" variant="secondary" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-site-visit-{{ $lead->id }}' })">{{ $latestVisit && !$latestVisit->is_completed ? 'Isi Data Cek Lokasi' : 'Cek Lokasi' }}</x-crm.button>@endif @endcan
            @can('convertToConsumer', $lead)
                @if($consumerAvailable && !$normalConsumer && (!$lead->project?->is_nup_eligible || !$nupConsumer))
                    <x-crm.button type="button" variant="secondary" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-consumer-{{ $lead->id }}' })">{{ $lead->project?->is_nup_eligible ? 'Proses Konsumen NUP' : 'Proses Menjadi Konsumen' }}</x-crm.button>
                @endif
            @endcan
            @can('submitToSlik', $lead) @if($slikAvailable && $normalConsumer && !$activeSlik)<x-crm.button type="button" variant="secondary" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-slik-{{ $lead->id }}' })">Kirim ke Cek SLIK</x-crm.button>@endif @endcan
            @can('markSlikRejected', $lead) @if($slikAvailable && $activeSlik)<x-crm.button type="button" variant="danger" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-slik-reject-{{ $lead->id }}' })">Tandai Tidak Lolos BI Checking</x-crm.button>@endif @endcan
            @can('convertToFreelance', $lead) @if($freelanceAvailable && !$freelance)<x-crm.button type="button" variant="secondary" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-freelance-{{ $lead->id }}' })">Ubah Menjadi Freelance</x-crm.button>@endif @endcan
            @if($normalConsumer || $nupConsumer)<x-crm.button type="button" variant="text" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-consumer-detail-{{ $lead->id }}' })">Lihat Data Konsumen</x-crm.button>@endif
            @if($freelance)<x-crm.button type="button" variant="text" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-freelance-detail-{{ $lead->id }}' })">Lihat Data Sales</x-crm.button>@endif
            @if($latestSlik)<x-crm.button type="button" variant="text" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-slik-detail-{{ $lead->id }}' })">Lihat BI Checking</x-crm.button>@endif
            @if($akad)<x-crm.button type="button" variant="text" size="sm" @click="$dispatch('oasis:modal-open', { name: 'lead-akad-detail-{{ $lead->id }}' })">Lihat Akad</x-crm.button>@endif
        </div>
        @if($sheetCapabilities === [])
            <p class="mt-2 text-xs text-amber-800">Kontrak spreadsheet cabang belum diverifikasi. Minta pengguna berwenang menjalankan Sinkronkan sebelum memakai tindakan siklus.</p>
        @endif
    @endcan
</section>

@can('recordSiteVisit', $lead)
<x-crm.modal name="lead-site-visit-{{ $lead->id }}" title="Cek Lokasi: {{ $lead->customer_name }}" description="Lengkapi kunjungan sekarang atau pilih Isi Nanti agar status tetap terlihat sebagai belum lengkap." size="lg" :initially-open="$autoOpenSiteVisit">
    <form method="POST" action="{{ route('sales-leads.site-visits.store', $lead) }}" x-data="{ submitting: false }" @submit="submitting = true" class="grid grid-cols-1 gap-3 sm:grid-cols-2" :aria-busy="submitting">
        @csrf <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
        <x-crm.field label="Tanggal" for="site-date-{{ $lead->id }}"><x-crm.date-field id="site-date-{{ $lead->id }}" name="tanggal" :value="now()->toDateString()" /></x-crm.field>
        <x-crm.field label="Waktu" for="site-time-{{ $lead->id }}"><select id="site-time-{{ $lead->id }}" name="waktu" class="sales-input"><option value="pagi">Pagi</option><option value="siang">Siang</option><option value="sore">Sore</option><option value="malam">Malam</option></select></x-crm.field>
        <x-crm.field label="Hasil" for="site-result-{{ $lead->id }}"><select id="site-result-{{ $lead->id }}" name="status" class="sales-input"><option value="follow up">Follow up</option><option value="non ok">Non OK</option><option value="utj">UTJ (hasil kunjungan saja)</option></select></x-crm.field>
        <x-crm.field label="Keterangan" for="site-notes-{{ $lead->id }}"><textarea id="site-notes-{{ $lead->id }}" name="keterangan" class="sales-input" rows="3"></textarea></x-crm.field>
        <div class="flex flex-wrap gap-2 sm:col-span-2"><x-crm.button type="submit" name="completion" value="complete" variant="primary" accent="sales" x-bind:disabled="submitting">Simpan Data Cek Lokasi</x-crm.button><x-crm.button type="submit" name="completion" value="isi_nanti" variant="secondary" x-bind:disabled="submitting">Isi Nanti</x-crm.button></div>
    </form>
</x-crm.modal>
@endcan

@can('convertToConsumer', $lead)
<x-crm.modal name="lead-consumer-{{ $lead->id }}" title="{{ $lead->project?->is_nup_eligible ? 'Input Konsumen NUP' : 'Jadikan Konsumen' }}" description="{{ $lead->project?->is_nup_eligible ? 'Data masuk ke data_konsumen_nup dan belum mengubah status menjadi UTJ.' : 'Data konsumen normal membutuhkan NIK dan ID kavling.' }}" size="lg">
    <form method="POST" action="{{ route('sales-leads.consumer.store', $lead) }}" x-data="{ submitting: false }" @submit="submitting = true" class="grid grid-cols-1 gap-3 sm:grid-cols-2" :aria-busy="submitting">
        @csrf <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="project_id" value="{{ $lead->project_id }}">
        <x-crm.field label="NIK" for="consumer-nik-{{ $lead->id }}" required><input id="consumer-nik-{{ $lead->id }}" name="nik" class="sales-input" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" required></x-crm.field>
        @if($lead->project?->is_nup_eligible)<x-crm.field label="Nomor NUP" for="consumer-nup-{{ $lead->id }}"><input id="consumer-nup-{{ $lead->id }}" name="nup" class="sales-input"></x-crm.field>@else<x-crm.field label="ID Kavling" for="consumer-kav-{{ $lead->id }}" required><input id="consumer-kav-{{ $lead->id }}" name="id_kavling" class="sales-input" required></x-crm.field>@endif
        <x-crm.field label="Tanggal Lahir" for="consumer-birth-{{ $lead->id }}"><x-crm.date-field id="consumer-birth-{{ $lead->id }}" name="tanggal_lahir" /></x-crm.field>
        <x-crm.field label="Pekerjaan" for="consumer-job-{{ $lead->id }}"><input id="consumer-job-{{ $lead->id }}" name="pekerjaan" class="sales-input"></x-crm.field>
        <x-crm.field label="Alamat" for="consumer-address-{{ $lead->id }}" class="sm:col-span-2"><textarea id="consumer-address-{{ $lead->id }}" name="alamat" class="sales-input" rows="3"></textarea></x-crm.field>
        <div class="sm:col-span-2"><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="submitting">Simpan {{ $lead->project?->is_nup_eligible ? 'NUP' : 'Konsumen' }}</x-crm.button></div>
    </form>
</x-crm.modal>
@endcan

@can('submitToSlik', $lead)
<x-crm.modal name="lead-slik-{{ $lead->id }}" title="Ajukan SLIK" description="Pengajuan menggunakan NIK dan ID kavling dari konsumen normal yang tertaut.">
    <form method="POST" action="{{ route('sales-leads.slik.store', $lead) }}" x-data="{ submitting: false }" @submit="submitting = true" class="space-y-3" :aria-busy="submitting">@csrf<input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}"><x-crm.field label="Tanggal SLIK" for="slik-date-{{ $lead->id }}" required><x-crm.date-field id="slik-date-{{ $lead->id }}" name="tanggal_slik" :value="now()->toDateString()" required /></x-crm.field><x-crm.field label="Keterangan" for="slik-notes-{{ $lead->id }}"><textarea id="slik-notes-{{ $lead->id }}" name="keterangan" class="sales-input" rows="3"></textarea></x-crm.field><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="submitting">Kirim SLIK</x-crm.button></form>
</x-crm.modal>
@endcan

@if($activeSlik) @can('markSlikRejected', $lead)
<x-crm.modal name="lead-slik-reject-{{ $lead->id }}" title="Konfirmasi Penolakan SLIK" description="Penolakan akan ditulis ke baris SLIK yang sama dan tidak dapat dibatalkan dari formulir ini.">
    <form method="POST" action="{{ route('sales-leads.slik.reject', [$lead, $activeSlik]) }}" x-data="{ submitting: false }" @submit="submitting = true" class="space-y-3" :aria-busy="submitting">@csrf @method('PATCH')<input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}"><x-crm.field label="Hasil SLIK" for="slik-result-{{ $lead->id }}" required><select id="slik-result-{{ $lead->id }}" name="hasil_slik" class="sales-input" required>@foreach(['KOL 1','KOL 2','KOL 3','KOL 4','KOL 5','NO BIC'] as $result)<option>{{ $result }}</option>@endforeach</select></x-crm.field><x-crm.field label="Keterangan Penolakan" for="slik-reason-{{ $lead->id }}" required><textarea id="slik-reason-{{ $lead->id }}" name="keterangan" class="sales-input" rows="4" required></textarea></x-crm.field><x-crm.button type="submit" variant="danger" x-bind:disabled="submitting">Simpan Penolakan</x-crm.button></form>
</x-crm.modal>
@endcan @endif

@can('convertToFreelance', $lead)
<x-crm.modal name="lead-freelance-{{ $lead->id }}" title="Selesaikan Data Freelance" description="Koordinator mengikuti atasan aktif Sales. Pilih koordinator hanya jika atasan tidak tersedia.">
    <form method="POST" action="{{ route('sales-leads.freelance.store', $lead) }}" x-data="{ submitting: false }" @submit="submitting = true" class="space-y-3" :aria-busy="submitting">@csrf<input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}"><x-crm.field label="NIK Koordinator" for="freelance-nik-{{ $lead->id }}" required><input id="freelance-nik-{{ $lead->id }}" name="nik_koordinator" class="sales-input" required></x-crm.field><x-crm.field label="Koordinator pengganti" for="freelance-coordinator-{{ $lead->id }}"><select id="freelance-coordinator-{{ $lead->id }}" name="coordinator_user_id" class="sales-input"><option value="">Gunakan atasan Sales</option>@foreach($coordinators as $coordinator)<option value="{{ $coordinator->id }}">{{ $coordinator->name }}</option>@endforeach</select></x-crm.field><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="submitting">Selesaikan Freelance</x-crm.button></form>
</x-crm.modal>
@endcan

@if($normalConsumer || $nupConsumer)
@php($consumerDetail = $normalConsumer ?? $nupConsumer)
<x-crm.modal name="lead-consumer-detail-{{ $lead->id }}" title="Data Konsumen" description="Referensi lokal yang tertaut ke spreadsheet cabang.">
    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div><dt class="font-bold">Jenis data</dt><dd>{{ $consumerDetail->sheet_type === 'data_konsumen_nup' ? 'Konsumen NUP' : 'Konsumen / UTJ' }}</dd></div>
        <div><dt class="font-bold">ID Kavling</dt><dd>{{ $consumerDetail->id_kavling ?: 'Belum tersedia' }}</dd></div>
        <div><dt class="font-bold">NIK</dt><dd>{{ $consumerDetail->nik ? str_repeat('*', max(strlen($consumerDetail->nik) - 4, 0)).substr($consumerDetail->nik, -4) : 'Belum tersedia' }}</dd></div>
        <div><dt class="font-bold">Sinkronisasi</dt><dd>{{ $consumerDetail->oasis_sync_id ?: 'Belum tertaut' }}</dd></div>
    </dl>
</x-crm.modal>
@endif

@if($freelance)
<x-crm.modal name="lead-freelance-detail-{{ $lead->id }}" title="Data Sales Freelance" description="Data freelance yang telah ditulis ke spreadsheet cabang.">
    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div><dt class="font-bold">NIK Sales</dt><dd>{{ $freelance->nik_sales }}</dd></div>
        <div><dt class="font-bold">Nama Sales</dt><dd>{{ $freelance->sales_name }}</dd></div>
        <div><dt class="font-bold">Koordinator</dt><dd>{{ $freelance->coordinator_name }}</dd></div>
        <div><dt class="font-bold">Sinkronisasi</dt><dd>{{ $freelance->oasis_sync_id ?: 'Belum tertaut' }}</dd></div>
    </dl>
</x-crm.modal>
@endif

@if($latestSlik)
<x-crm.modal name="lead-slik-detail-{{ $lead->id }}" title="Data BI Checking" description="Pengajuan SLIK yang tertaut ke konsumen lead.">
    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div><dt class="font-bold">Tanggal SLIK</dt><dd>{{ $latestSlik->slik_date?->format('d/m/Y') ?: 'Belum tersedia' }}</dd></div>
        <div><dt class="font-bold">ID Kavling</dt><dd>{{ $latestSlik->id_kavling ?: 'Belum tersedia' }}</dd></div>
        <div><dt class="font-bold">Hasil</dt><dd>{{ $latestSlik->slik_result ?: 'Menunggu hasil' }}</dd></div>
        <div><dt class="font-bold">Status</dt><dd>{{ $latestSlik->status }}</dd></div>
    </dl>
</x-crm.modal>
@endif

@if($akad)
<x-crm.modal name="lead-akad-detail-{{ $lead->id }}" title="Data Akad" description="Akad dicerminkan otomatis dari spreadsheet cabang.">
    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div><dt class="font-bold">Tanggal Akad</dt><dd>{{ $akad->akad_at?->format('d/m/Y') ?: 'Belum tersedia' }}</dd></div>
        <div><dt class="font-bold">ID Kavling</dt><dd>{{ $akad->id_kavling ?: 'Belum tersedia' }}</dd></div>
        <div><dt class="font-bold">Referensi Akad</dt><dd>{{ $akad->akad_reference ?: 'Belum tersedia' }}</dd></div>
        <div><dt class="font-bold">Status</dt><dd>{{ $akad->status }}</dd></div>
    </dl>
</x-crm.modal>
@endif
