@extends('layouts.crm')

@section('title', 'Agenda Saya - Oasis CRM')

@section('content')
@php($canCleanup = auth()->user()->hasPrimaryRole('superadmin') || (app(\App\Services\ImpersonationService::class)->isActive(request()) && app(\App\Services\ImpersonationService::class)->originalUser(request())?->isSuperadmin()))
<div class="space-y-4">
    @if($dailyReminder['shouldShow']) @include('crm.sales-pocketbook._daily-reminder', ['dailyReminder' => $dailyReminder]) @endif
    <x-crm.page-header variant="canonical" eyebrow="Workspace Pribadi" title="Agenda Saya" description="Catat dan selesaikan agenda sales Anda.">
        <x-slot:actions>
            <x-crm.button variant="secondary" :href="route('sales-agendas.export')">Export XLSX</x-crm.button>
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
                    @foreach(['customer_name' => 'Nama Calon Konsumen', 'phone' => 'No. WhatsApp / Telepon'] as $name => $label)<label class="text-xs font-bold uppercase">{{ $label }}<input name="{{ $name }}" value="{{ old($name) }}" class="mt-1 block w-full border border-gray-300 px-3 py-2" @required($name === 'customer_name')></label>@endforeach
                    @foreach(['source' => ['Sumber Lead', \App\Support\SalesLeadMasterData::SOURCES], 'platform' => ['Kanal Masuk', \App\Support\SalesLeadMasterData::CHANNELS], 'campaign_name' => ['Aktivitas Lead', \App\Support\SalesLeadMasterData::ACTIVITIES]] as $name => [$label, $options])<label class="text-xs font-bold uppercase">{{ $label }}<select name="{{ $name }}" class="mt-1 block w-full border border-gray-300 px-3 py-2" required><option value="">Pilih {{ strtolower($label) }}</option>@foreach($options as $option)<option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>@endforeach</select></label>@endforeach
                    <label class="text-xs font-bold uppercase">Status Lead<select name="current_status" class="mt-1 block w-full border border-gray-300 px-3 py-2" required><option value="no_response">No Respon</option><option value="discussion" @selected(old('current_status') === 'discussion')>Diskusi</option><option value="site_visit" @selected(old('current_status') === 'site_visit')>Cek Lokasi</option></select></label>
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
                                            <span class="flex items-center gap-2"><a class="font-bold text-[#0000ee] underline" href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}">Foto {{ $loop->iteration }}</a>@unless($agenda->isFinished())<form method="POST" action="{{ route('sales-agendas.evidence.destroy', [$agenda, $evidence]) }}">@csrf @method('DELETE')<button type="submit" class="font-bold text-red-700 underline" onclick="return confirm('Hapus bukti foto ini?')">Hapus</button></form>@endunless</span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @unless($agenda->isFinished())
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
                        @endunless
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
