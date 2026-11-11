@extends('layouts.crm')

@section('title', 'Buku Saku Koordinator - Oasis CRM')
@section('body-class', 'coordinator-workspace-page')

@section('content')
@php
    $initialSalesId = (string) old('sales_user_id', $salesUsers->first()?->id ?? '');
    $initialProjects = $projectsBySales->get($initialSalesId, collect());
    $initialProjectId = (string) old('project_id', $initialProjects->count() === 1 ? $initialProjects->first()['id'] : '');
    $agendaStatuses = [
        'planned' => ['Direncanakan', 'pending'],
        'confirmed' => ['Dikonfirmasi', 'info'],
        'done' => ['Selesai', 'success'],
        'cancelled' => ['Dibatalkan', 'inactive'],
        'rescheduled' => ['Dijadwalkan Ulang', 'warning'],
    ];
    $syncStatuses = [
        'pending_create' => ['Belum Sync', 'pending'],
        'synced' => ['Tersinkron', 'success'],
        'pending_update' => ['Perlu Sync', 'warning'],
        'sync_failed' => ['Sync Gagal', 'danger'],
    ];
    $tabParams = array_filter([
        'period' => $filters['period'] ?? null,
        'date_from' => $filters['date_from'] ?? null,
        'date_to' => $filters['date_to'] ?? null,
        'sales_id' => $filters['sales_id'] ?? null,
        'stale_days' => $filters['stale_days'] ?? null,
    ], fn ($value) => filled($value));
    $tabUrl = fn (string $target) => route('sales-pocketbook.index', array_merge($tabParams, ['tab' => $target]));
@endphp
<div class="sales-pocketbook-workspace">
<div class="space-y-4" x-data="{ sales: @js($initialSalesId), project: @js($initialProjectId), branch: @js((string) old('branch_id', $initialProjects->count() === 1 ? $initialProjects->first()['branch_id'] : '')), leadDate: @js($leadDate), promo: @js(old('promo_name', 'No Promo')), promos: @js($promos), promoEndpoint: @js($promoOptionsEndpoint), projectsBySales: @js($projectsBySales), get projects() { return this.projectsBySales[this.sales] || [] }, salesChanged() { this.project = this.projects.length === 1 ? this.projects[0].id : ''; this.projectChanged() }, agendaSalesChanged() { this.project = this.projects.length === 1 ? this.projects[0].id : '' }, projectChanged() { this.branch = this.projects.find(item => item.id === this.project)?.branch_id || ''; this.loadPromos() }, async loadPromos() { if (!this.project || !this.leadDate) { this.promos = ['No Promo']; this.promo = 'No Promo'; return } const url = new URL(this.promoEndpoint.replace('PROJECT_ID', this.project), window.location.origin); url.searchParams.set('date', this.leadDate); const response = await fetch(url, { headers: { Accept: 'application/json' } }); if (!response.ok) { this.promos = ['No Promo']; this.promo = 'No Promo'; return } this.promos = (await response.json()).options; if (!this.promos.includes(this.promo)) this.promo = 'No Promo' } }">
    @if($tab === 'today')
        <header class="coordinator-mobile-header">
            <a href="{{ route(auth()->user()->landingRouteName()) }}">OASIS CRM</a>
            <a class="coordinator-mobile-avatar" href="{{ route('profile.edit') }}" aria-label="Buka profil {{ auth()->user()->name }}">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</a>
        </header>
        <header class="coordinator-today-heading">
            <h1>Buku Saku Tim</h1>
            <p>Koordinator · {{ auth()->user()->branch?->name ?? 'Cabang belum ditentukan' }} · {{ $salesUsers->count() }} Sales</p>
        </header>
        <div class="coordinator-desktop-heading">
            <x-crm.page-header variant="canonical" eyebrow="Koordinator Sales" title="Buku Saku Tim" description="Pantau rencana dan aktivitas Sales dalam tim Anda." />
        </div>
    @else
        <x-crm.page-header variant="canonical" eyebrow="Koordinator Sales" title="Buku Saku Sales" description="Kelola Lead tim, pantau Agenda Sales, dan tinjau laporan performa.">
            @if($tab === 'lead')
                <x-slot:actions>
                    @if($canCreate)<x-crm.button variant="primary" accent="sales" href="#coordinator-lead-input">INPUT LEAD</x-crm.button>@endif
                </x-slot:actions>
            @endif
        </x-crm.page-header>
    @endif

    @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan">{{ $errors->first() }}</x-crm.alert>@endif

    @if($tab !== 'today')
    <x-crm.section id="coordinator-team" title="Tim Sales Saya" :description="$salesUsers->isEmpty() ? 'Belum ada Sales aktif dalam Tim Sales Saya.' : $salesUsers->count().' Sales: '.$salesUsers->pluck('name')->join(', ')" class="sales-pocketbook-screen-section" />
    @endif
    <nav class="sales-pocketbook-tabs crm-horizontal-tabs" data-horizontal-tabs aria-label="Workspace Koordinator Sales">
        @foreach(['today' => 'Hari ini', 'lead' => 'Lead', 'report' => 'Laporan'] as $key => $label)
            <a href="{{ $tabUrl($key) }}" @if($tab === $key) aria-current="page" @endif class="sales-pocketbook-tab {{ $tab === $key ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab !== 'today')
    <x-crm.section id="coordinator-filters" title="Filter Tim & Periode" :description="$period['from']->format('d/m/Y').' - '.$period['to']->format('d/m/Y')" class="sales-pocketbook-screen-section">
        <x-crm.toolbar label="Filter periode koordinator">
            <form method="GET" class="grid w-full gap-3 md:grid-cols-4" x-data="{ period: @js($filters['period']) }">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="hidden" name="period" :value="period">
                <div class="flex flex-wrap gap-2 md:col-span-4">
                    @foreach(['today' => 'Hari Ini', 'week' => 'Minggu Ini', 'month' => 'Bulan Ini', 'custom' => 'Kustom'] as $value => $label)
                        <button type="button" class="border-2 border-black px-3 py-2 text-xs font-bold" :class="period === @js($value) ? 'bg-[#fcc20f]' : 'bg-white'" :aria-pressed="period === @js($value)" @click="period = @js($value); if (period !== 'custom') $nextTick(() => $el.form.requestSubmit())">{{ $label }}</button>
                    @endforeach
                </div>
                <div x-show="period === 'custom'" x-cloak><x-crm.field label="Dari" for="monitor-date-from"><x-crm.date-field id="monitor-date-from" name="date_from" :value="$filters['date_from']" x-bind:disabled="period !== 'custom'" /></x-crm.field></div>
                <div x-show="period === 'custom'" x-cloak><x-crm.field label="Sampai" for="monitor-date-to"><x-crm.date-field id="monitor-date-to" name="date_to" :value="$filters['date_to']" x-bind:disabled="period !== 'custom'" /></x-crm.field></div>
                <x-crm.field label="Sales" for="monitor-sales"><select id="monitor-sales" class="sales-input" name="sales_id"><option value="">Semua Sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" @selected((string) $filters['sales_id'] === (string) $sales->id)>{{ $sales->name }}</option>@endforeach</select></x-crm.field>
                @if($tab === 'lead')<x-crm.field label="Belum ditindaklanjuti" for="monitor-stale-days"><select id="monitor-stale-days" class="sales-input" name="stale_days"><option value="2" @selected($staleDays === 2)>2 hari</option>@foreach([3,5,7,14] as $days)<option value="{{ $days }}" @selected($staleDays === $days)>{{ $days }} hari</option>@endforeach</select></x-crm.field>@endif
                <div class="self-end"><x-crm.button type="submit" variant="secondary">Terapkan</x-crm.button></div>
            </form>
        </x-crm.toolbar>
    </x-crm.section>
    @endif

    @if($tab === 'today')
        @php
            $todayUrl = route('sales-pocketbook.index', ['tab' => 'today', 'view' => 'today']);
            $plansUrl = route('sales-pocketbook.index', ['tab' => 'today', 'view' => 'plans']);
            $updatesUrl = route('sales-pocketbook.index', ['tab' => 'today', 'view' => 'updates']);
        @endphp
        <nav class="coordinator-today-segments" aria-label="Antrean kerja koordinator">
            <a href="{{ $todayUrl }}" @if($todayView === 'today') aria-current="page" @endif>Ringkasan</a>
            <a href="{{ $plansUrl }}" @if($todayView === 'plans') aria-current="page" @endif>Rencana</a>
            <a href="{{ $updatesUrl }}" @if($todayView === 'updates') aria-current="page" @endif>Perlu update</a>
        </nav>
        @if($todayView === 'today')
            @php
                $priorityCounts = collect($todayWorkspace['cards'])->countBy('priority');
            @endphp
            <section class="coordinator-today-summary" aria-label="Ringkasan hari ini">
                <div><strong>{{ $todayWorkspace['submitted_count'] }} / {{ $todayWorkspace['sales_count'] }}</strong><span>rencana terkirim</span></div>
                <div><strong>{{ $todayWorkspace['completed_agenda_count'] }} / {{ $todayWorkspace['agenda_count'] }}</strong><span>agenda selesai</span></div>
            </section>
            <section class="coordinator-action-queue" aria-labelledby="coordinator-action-queue-title">
                <div class="coordinator-action-queue-heading">
                    <div><h2 id="coordinator-action-queue-title">Perlu tindakan hari ini</h2><p>Mulai dari Sales dengan pekerjaan yang paling mendesak.</p></div>
                    <span>{{ $todayWorkspace['sales_count'] }} Sales</span>
                </div>
                <div class="coordinator-action-queue-grid">
                    <div><strong>{{ $priorityCounts->get(0, 0) }}</strong><span>Belum kirim rencana</span></div>
                    <div><strong>{{ $priorityCounts->get(1, 0) }}</strong><span>Perlu update</span></div>
                    <div><strong>{{ $priorityCounts->get(2, 0) }}</strong><span>Berjalan</span></div>
                    <div><strong>{{ $priorityCounts->get(3, 0) }}</strong><span>Selesai</span></div>
                </div>
            </section>
            @if($todayWorkspace['incomplete_count'] > 0)
                <a class="coordinator-backlog-callout" href="{{ $updatesUrl }}">
                    <strong>! {{ $todayWorkspace['incomplete_count'] }} agenda sebelumnya belum diupdate</strong>
                    <span>{{ $todayWorkspace['incomplete_agendas']->keys()->map(fn ($id) => $salesUsers->firstWhere('id', $id)?->name)->filter()->join(', ') }} · ketuk untuk tinjau ›</span>
                </a>
            @endif
            <section class="coordinator-sales-today" aria-labelledby="coordinator-sales-today-title">
                <div class="coordinator-sales-list-heading"><h2 id="coordinator-sales-today-title">Sales hari ini</h2><span>{{ $todayWorkspace['sales_count'] }} Sales</span></div>
                <div class="coordinator-sales-cards">
                @forelse($todayWorkspace['cards'] as $card)
                    @php
                        [$priorityLabel, $priorityClass] = match ($card['priority']) {
                            0 => ['Belum kirim', 'is-not-submitted'],
                            1 => ['Perlu update', 'is-needs-update'],
                            3 => ['Selesai', 'is-complete'],
                            default => ['Berjalan', 'is-active'],
                        };
                    @endphp
                    <a class="coordinator-sales-card" href="{{ route('sales-pocketbook.index', ['tab' => 'today', 'view' => 'detail', 'sales_id' => $card['sales']->id]) }}">
                        <div class="coordinator-sales-card-heading"><strong>{{ $card['sales']->name }}</strong><span class="{{ $priorityClass }}">{{ $priorityLabel }}</span></div>
                        @if($card['summary']['agenda_count'] > 0)
                            <p>{{ $card['summary']['agenda_count'] }} agenda · {{ $card['summary']['normal_minutes'] }} mnt</p>
                            <div class="coordinator-progress" role="progressbar" aria-label="Progres agenda {{ $card['sales']->name }}" aria-valuemin="0" aria-valuemax="{{ max(1, $card['summary']['agenda_count']) }}" aria-valuenow="{{ $card['summary']['completed_count'] }}"><span style="width: {{ min(100, round($card['summary']['completed_count'] / $card['summary']['agenda_count'] * 100)) }}%"></span></div>
                        @else
                            <p>Rencana belum dikirim</p>
                        @endif
                        @if($card['backlog_count'] > 0)<small>! {{ $card['backlog_count'] }} agenda sebelumnya belum diupdate</small>@endif
                    </a>
                @empty
                    <x-crm.empty-state title="Belum ada Sales dalam tim aktif." />
                @endforelse
                </div>
            </section>
        @elseif($todayView === 'updates')
            <section class="coordinator-backlog-list" aria-labelledby="coordinator-updates-title">
                <h2 id="coordinator-updates-title">Perlu diupdate</h2>
                <p>Agenda hari-hari sebelumnya pada minggu ini yang belum memiliki hasil atau bukti.</p>
                @if(session('coordinator_reminder_confirmation'))
                    @php
                        $reminderConfirmation = session('coordinator_reminder_confirmation');
                    @endphp
                    <aside class="coordinator-backlog-callout" role="alert"><strong>Pengingat dalam 24 jam terakhir</strong><span>{{ count($reminderConfirmation['sales_ids']) }} Sales perlu konfirmasi untuk dikirimi ulang. {{ session('coordinator_reminder_sent', 0) }} Sales lainnya sudah dikirimi.</span>
                        <form method="POST" action="{{ route('coordinator-agenda-reminders.store') }}">@csrf
                            @foreach($reminderConfirmation['sales_ids'] as $salesId)<input type="hidden" name="sales_ids[]" value="{{ $salesId }}">@endforeach
                            <input type="hidden" name="confirm_recent" value="1"><button class="coordinator-reminder-confirm" type="submit">Konfirmasi kirim ulang</button>
                        </form>
                    </aside>
                @endif
                <form class="coordinator-reminder-form" method="POST" action="{{ route('coordinator-agenda-reminders.store') }}">@csrf
                @forelse($todayWorkspace['incomplete_agendas'] as $salesId => $items)
                    <fieldset class="coordinator-backlog-group"><legend><label><input type="checkbox" name="sales_ids[]" value="{{ $salesId }}" checked> {{ $salesUsers->firstWhere('id', $salesId)?->name }}</label></legend>
                        @foreach($items as $agenda)
                            <article class="coordinator-backlog-item"><time>{{ $agenda->scheduled_date?->locale('id')->translatedFormat('D, d M') }} · {{ substr((string) $agenda->start_time, 0, 5) }}</time><strong>{{ $agenda->title }}</strong><div>@if($agenda->missing_result)<span>Belum ada hasil</span>@endif @if($agenda->missing_evidence)<span>Belum ada bukti</span>@endif</div></article>
                        @endforeach
                    </fieldset>
                @empty
                    <x-crm.empty-state title="Semua agenda sudah diupdate" />
                @endforelse
                    @if($todayWorkspace['incomplete_agendas']->isNotEmpty())<div class="coordinator-reminder-sticky"><button type="submit">Kirim pengingat · {{ $todayWorkspace['incomplete_agendas']->count() }} Sales</button></div>@endif
                </form>
            </section>
        @elseif($todayView === 'plans')
            <nav class="coordinator-plan-choices" aria-label="Pilih hari rencana">
                @foreach(['tomorrow' => 'Besok', 'saturday' => 'Sabtu', 'sunday' => 'Minggu'] as $dayKey => $dayLabel)
                    <a href="{{ route('sales-pocketbook.index', ['tab' => 'today', 'view' => 'plans', 'plan_day' => $dayKey]) }}" @if($planDayKey === $dayKey) aria-current="date" @endif>{{ $dayLabel }} @if($dayKey !== 'tomorrow')<small>akhir pekan</small>@endif</a>
                @endforeach
            </nav>
            <section class="coordinator-plan-list" aria-label="Rencana tim">
                @foreach($upcomingPlans as $planDay)
                    <section class="coordinator-plan-day">
                        <h2>{{ $planDay['date']->isWeekend() ? strtoupper($planDay['date']->locale('id')->translatedFormat('l')).' · AKHIR PEKAN' : 'Besok · '.$planDay['date']->locale('id')->translatedFormat('l, d F') }}</h2>
                        @php
                            $salesWithoutPlan = collect($planDay['items'])->filter(
                                fn ($plan) => ! $plan['summary']['submitted'] && $plan['summary']['agenda_count'] === 0,
                            );
                        @endphp
                        @if($salesWithoutPlan->isNotEmpty())
                            <aside class="coordinator-backlog-callout">
                                <strong>! {{ $salesWithoutPlan->count() }} Sales belum punya rencana {{ $planDay['date']->locale('id')->translatedFormat('l') }}</strong>
                                <form method="POST" action="{{ route('coordinator-agenda-reminders.store') }}">
                                    @csrf
                                    @foreach($salesWithoutPlan as $plan)
                                        <input type="hidden" name="sales_ids[]" value="{{ $plan['sales']->id }}">
                                    @endforeach
                                    <button type="submit">Ingatkan semua</button>
                                </form>
                            </aside>
                        @endif
                        @foreach($planDay['items'] as $plan)
                            <article class="coordinator-plan-card">
                                <strong>{{ $plan['sales']->name }}</strong>
                                <span>{{ $plan['summary']['submitted'] ? 'Terkirim' : ($plan['summary']['agenda_count'] ? 'Draft' : 'Belum ada') }}</span>
                                <p>
                                    {{ $plan['summary']['normal_minutes'] }} / {{ $plan['summary']['requires_target'] ? '480 menit' : '—' }}
                                    @if($plan['summary']['remaining_minutes'] > 0)
                                        · kurang {{ $plan['summary']['remaining_minutes'] }} menit
                                    @endif
                                </p>
                                @foreach($plan['agenda_items'] as $planAgenda)
                                    @if($planAgenda->rescheduled_from_id)
                                        <small class="coordinator-rescheduled-badge">
                                            SUSULAN · diisi {{ $planAgenda->created_at?->format('d/m') }} untuk {{ $planAgenda->rescheduledFrom?->scheduled_date?->format('d/m') }}
                                        </small>
                                    @endif
                                @endforeach
                            </article>
                        @endforeach
                    </section>
                @endforeach
            </section>
        @else
            @php
                $detailSalesId = $salesDetail['sales']->id;
                $detailDate = $salesDetail['selected_date'];
                $detailUrl = fn ($day) => route('sales-pocketbook.index', ['tab' => 'today', 'view' => 'detail', 'sales_id' => $detailSalesId, 'detail_date' => $day->toDateString()]);
                $detailUpdatesUrl = route('sales-pocketbook.index', ['tab' => 'today', 'view' => 'updates', 'sales_id' => $detailSalesId]);
            @endphp
            <section class="coordinator-sales-detail" aria-labelledby="coordinator-detail-name">
                <header><a href="{{ $todayUrl }}">← Ringkasan</a><h2 id="coordinator-detail-name">{{ $salesDetail['sales']->name }}</h2><p>{{ $salesDetail['sales']->branch?->name }} · {{ $salesDetail['sales']->resolved_project_name ?: 'Proyek belum ditentukan' }}</p></header>
                @if($salesDetail['backlog']->isNotEmpty())<a class="coordinator-backlog-callout" href="{{ $updatesUrl }}"><strong>! {{ $salesDetail['backlog']->count() }} agenda sebelumnya perlu dilengkapi</strong></a>@endif
                <nav class="coordinator-week-strip" aria-label="Pilih hari dalam minggu ini">
                    @foreach($salesDetail['week'] as $day)
                        @php
                            [$daySymbol, $dayText] = match ($day['state']) {
                                'complete' => ['✓', 'Lengkap'],
                                'incomplete' => ['!', 'Perlu dilengkapi'],
                                'weekend_empty' => ['–', 'Akhir pekan'],
                                default => ['▫', 'Hari kerja tanpa rencana'],
                            };
                        @endphp
                        <a href="{{ $detailUrl($day['date']) }}" @if($detailDate->isSameDay($day['date'])) aria-current="date" @endif aria-label="{{ $day['date']->locale('id')->translatedFormat('l j F') }}: {{ $dayText }}"><span>{{ $day['date']->locale('id')->translatedFormat('D') }}</span><strong>{{ $daySymbol }}</strong><small>{{ $day['date']->format('j') }}</small></a>
                    @endforeach
                </nav>
                <h3>Agenda · {{ $detailDate->locale('id')->translatedFormat('l, j F') }}</h3>
                <div class="coordinator-detail-agendas">
                    @forelse($salesDetail['agendas'] as $agenda)
                        <article class="coordinator-detail-agenda" id="agenda-result-{{ $agenda->id }}">
                            <div class="coordinator-agenda-time">{{ substr((string) $agenda->start_time, 0, 5) }} · {{ $agenda->duration_minutes ?: 0 }} menit</div>
                            <strong>{{ $agenda->title }}</strong>
                            <span>{{ $agenda->status === 'done' ? 'Selesai · '.$agenda->evidence->whereNull('purged_at')->count().' foto' : (in_array($agenda->status, ['cancelled', 'rescheduled'], true) ? 'Ditunda' : 'Menunggu') }}</span>
                            @if($agenda->activity_result)<p>{{ $agenda->activity_result }}</p>@endif
                            @foreach($agenda->evidence->whereNull('purged_at') as $evidence)<a href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}">Lihat bukti · {{ $evidence->original_name }}</a>@endforeach
                            @if($canManageAgenda && !in_array($agenda->status, ['done', 'cancelled', 'rescheduled'], true))
                                <form method="POST" action="{{ route('sales-agendas.update', $agenda) }}" class="coordinator-result-form">@csrf @method('PATCH')<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}"><label for="result-{{ $agenda->id }}">Isi hasil atas nama Sales</label><textarea id="result-{{ $agenda->id }}" name="activity_result" required></textarea><button type="submit">Simpan hasil</button></form>
                            @endif
                        </article>
                    @empty
                        <x-crm.empty-state title="Belum ada agenda pada tanggal ini." />
                    @endforelse
                </div>
                @php
                    $firstEditableAgenda = null;
                @endphp
                @if($salesDetail['agendas']->isNotEmpty())
                    @php
                        $firstEditableAgenda = $salesDetail['agendas']->first(fn ($agenda) => ! in_array($agenda->status, ['done', 'cancelled', 'rescheduled'], true));
                    @endphp
                @endif
                @if($salesDetail['backlog']->isNotEmpty() || $firstEditableAgenda)
                    <div class="coordinator-detail-sticky-actions">
                        @if($salesDetail['backlog']->isNotEmpty())<a href="{{ $detailUpdatesUrl }}">Ingatkan</a>@endif
                        @if($firstEditableAgenda)<a href="#agenda-result-{{ $firstEditableAgenda->id }}">Isi hasil</a>@endif
                    </div>
                @endif
            </section>
        @endif
    @elseif($tab === 'lead')
        <section class="coordinator-lead-actions" aria-labelledby="coordinator-lead-actions-title">
            <h2 id="coordinator-lead-actions-title">Perlu tindakan</h2>
            @if($actionableLeads->isNotEmpty())
                @php
                    $actionablePreview = $actionableLeads->take(5);
                    $actionableListUrl = route('sales-pocketbook.index', array_merge($filters, ['tab' => 'lead', 'stale_days' => $staleDays, 'focus' => 'stale']));
                @endphp
                <div class="coordinator-lead-warning"><strong>{{ $actionableLeads->count() }} lead belum ditindaklanjuti ≥ {{ $staleDays }} hari</strong><ul>@foreach($actionablePreview as $lead)<li><a href="{{ route('sales-leads.show', $lead) }}">{{ $lead->customer_name }} · {{ $lead->sales?->name }} · {{ $lead->current_status->label() }}</a></li>@endforeach</ul>@if($actionableLeads->count() > $actionablePreview->count())<a class="coordinator-lead-queue-link" href="{{ $actionableListUrl }}#coordinator-team-leads">Lihat semua lead yang perlu ditindaklanjuti</a>@endif</div>
            @endif
            @if($failedSyncLeads->isNotEmpty())
                <div class="coordinator-lead-warning"><strong>{{ $failedSyncLeads->count() }} lead gagal sinkron Google Sheet</strong><p>Dikelompokkan untuk pemeriksaan ulang; detail error teknis tidak ditampilkan.</p>
                    @if($canSync)<form method="POST" action="{{ route('coordinator-leads.sync') }}">@csrf<button type="submit">Coba sinkronkan</button></form>@endif
                </div>
            @endif
            @if($actionableLeads->isEmpty() && $failedSyncLeads->isEmpty())<p class="coordinator-lead-clear">✓ Tidak ada lead yang perlu tindakan segera.</p>@endif
        </section>
        @if($canCreate)<a class="coordinator-lead-sticky-create" href="#coordinator-lead-input">+ Input lead</a>@endif
        @if($canCreate && $salesUsers->isNotEmpty())
            <x-crm.section id="coordinator-lead-input" title="Input Lead Tim" class="sales-pocketbook-screen-section sales-pocketbook-composer">
                <form method="POST" action="{{ route('sales-leads.store') }}" class="coordinator-lead-wizard" x-data="{ leadStep: 1 }" novalidate>@csrf
                    <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) Illuminate\Support\Str::uuid()) }}">
                    <div class="coordinator-wizard-progress" aria-live="polite"><strong x-text="`Langkah ${leadStep} dari 3`"></strong><span x-show="leadStep === 1">Sales & proyek</span><span x-show="leadStep === 2">Data konsumen</span><span x-show="leadStep === 3">Sumber & status</span></div>
                    <fieldset class="coordinator-wizard-step" x-ref="stepOne" x-show="leadStep === 1"><legend>1. Sales & proyek</legend><input type="hidden" name="branch_id" x-model="branch"><x-crm.field label="Sales PIC" for="coordinator-lead-sales" required><select id="coordinator-lead-sales" class="sales-input" name="sales_user_id" x-model="sales" @change="salesChanged()" required><option value="">Pilih Sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}">{{ $sales->name }}</option>@endforeach</select></x-crm.field><x-crm.field label="Proyek" for="coordinator-lead-project" required><select id="coordinator-lead-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()" required><option value="">Pilih proyek</option><template x-for="item in projects" :key="item.id"><option :value="item.id" x-text="item.name"></option></template></select><p x-show="projects.length === 0" class="mt-1 text-xs text-red-700">Sales belum memiliki proyek aktif. Lead tidak dapat disimpan.</p></x-crm.field><div class="crm-field"><span class="crm-field-label">Cabang</span><div class="crm-field-control border border-gray-400 bg-gray-100 px-3 py-2 text-sm" x-text="projects.find(item => item.id === project)?.branch_name || 'Dipilih otomatis dari proyek'"></div></div><button type="button" @click="if ($refs.stepOne.reportValidity()) leadStep = 2" :disabled="!sales || !project">Lanjut ke data konsumen</button></fieldset>
                    <fieldset class="coordinator-wizard-step" x-ref="stepTwo" x-show="leadStep === 2" x-cloak><legend>2. Data konsumen</legend><x-crm.field label="Tanggal Lead" for="coordinator-lead-date" required><x-crm.date-field id="coordinator-lead-date" name="lead_date" :value="$leadDate" x-model="leadDate" @change="loadPromos()" required /></x-crm.field><x-crm.field label="Nama Calon Konsumen" for="coordinator-lead-name" required><input id="coordinator-lead-name" class="sales-input" name="customer_name" value="{{ old('customer_name') }}" placeholder="Masukkan nama calon konsumen" required></x-crm.field><x-crm.field label="No. WhatsApp / Telepon" for="coordinator-lead-phone" required><input id="coordinator-lead-phone" class="sales-input" name="phone" value="{{ old('phone') }}" placeholder="Contoh: 081234567890" required></x-crm.field><div class="coordinator-wizard-actions"><button type="button" @click="leadStep = 1">Kembali</button><button type="button" @click="if ($refs.stepTwo.reportValidity()) leadStep = 3">Lanjut ke sumber & status</button></div></fieldset>
                    <fieldset class="coordinator-wizard-step" x-show="leadStep === 3" x-cloak><legend>3. Sumber & status</legend>@foreach([['Sumber Lead','source',$sources,'Pilih sumber lead'],['Kanal Masuk','platform',$channels,'Pilih kanal masuk'],['Aktivitas Lead','campaign_name',$activities,'Pilih aktivitas lead']] as [$label,$name,$options,$placeholder])<x-crm.field :label="$label" :for="'coordinator-lead-'.$name" required><select id="coordinator-lead-{{ $name }}" class="sales-input" name="{{ $name }}" required><option value="">{{ $placeholder }}</option>@foreach($options as $option)<option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>@endforeach</select></x-crm.field>@endforeach<x-crm.field label="Nama Promo" for="coordinator-lead-promo" hint="Pilih promo sesuai proyek dan tanggal lead."><select id="coordinator-lead-promo" class="sales-input" name="promo_name" x-model="promo"><template x-for="option in promos" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field><x-crm.field label="Status Lead" for="coordinator-lead-status" required><select id="coordinator-lead-status" class="sales-input" name="current_status" required><option value="">Pilih status lead</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(old('current_status', 'no_response') === $status->value)>{{ $status->label() }}</option>@endforeach</select></x-crm.field><x-crm.field label="Catatan" for="coordinator-lead-notes"><textarea id="coordinator-lead-notes" class="sales-input" name="notes" rows="2" placeholder="Tambahkan catatan lead">{{ old('notes') }}</textarea></x-crm.field><div class="coordinator-wizard-actions"><button type="button" @click="leadStep = 2">Kembali</button><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="!branch || !project">Simpan Lead</x-crm.button></div></fieldset>
                </form>
            </x-crm.section>
        @endif

        <x-crm.section id="coordinator-team-leads" title="Lead Tim" description="Lead yang dicatat untuk Sales aktif dalam tim Anda." class="sales-pocketbook-screen-section">
            <x-slot:actions><x-crm.status-badge variant="neutral">{{ $leads->total() }} Lead</x-crm.status-badge></x-slot:actions>
            <div class="coordinator-lead-cards">@forelse($leads as $lead)<a class="coordinator-lead-card" href="{{ route('sales-leads.show', $lead) }}"><div><strong>{{ $lead->customer_name }}</strong><span>{{ $lead->current_status->label() }}</span></div><p>{{ $lead->sales?->name }} · {{ $lead->lead_date->format('d/m/Y') }}</p><small>{{ match($lead->sync_status) { 'synced' => 'Sinkron ✓', 'sync_failed' => 'Sinkron gagal', 'pending_update' => 'Menunggu sinkron', default => 'Menunggu sinkron' } }}</small></a>@empty<x-crm.empty-state title="Belum ada lead tim" description="Lead anggota aktif tim pada periode ini akan muncul di sini." />@endforelse</div>
            <div class="crm-table-scroll coordinator-lead-table"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Sales PIC</th><th>Cabang</th><th>Proyek</th><th>Promo</th><th>Status Lead</th><th>Status Sync</th><th>Aksi</th></tr></thead><tbody>@forelse($leads as $lead)
                @php
                    [$syncStatusLabel, $syncStatusVariant] = $syncStatuses[$lead->sync_status] ?? [ucfirst(str_replace('_', ' ', $lead->sync_status)), 'neutral'];
                @endphp
                <tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->sales?->name }}</td><td>{{ $lead->branch?->name }}</td><td>{{ $lead->project?->project_name }}</td><td>{{ $lead->id_promo ?: '-' }}</td><td>{{ $lead->current_status->label() }}</td><td><x-crm.status-badge :variant="$syncStatusVariant">{{ $syncStatusLabel }}</x-crm.status-badge></td><td><div class="flex gap-2">@can('viewSiteVisit', $lead)<x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)">Detail</x-crm.button>@endcan @can('update', $lead)<x-crm.button variant="text" size="sm" :href="route('sales-leads.edit', $lead)">Edit</x-crm.button>@endcan @can('markUtjDirect', $lead) @if($lead->current_status->isManual())<x-crm.button type="button" variant="text" size="sm" @click="$dispatch('oasis:modal-open', { name: 'coordinator-utj-{{ $lead->id }}' })">Tandai UTJ</x-crm.button><x-crm.modal name="coordinator-utj-{{ $lead->id }}" title="Tandai UTJ: {{ $lead->customer_name }}" description="Lead langsung ditandai UTJ tanpa mencatat data cek lokasi atau konsumen."><form method="POST" action="{{ route('sales-leads.utj.store', $lead) }}" x-data="{ submitting: false }" @submit="submitting = true" class="space-y-3" :aria-busy="submitting">@csrf<input type="hidden" name="operation_uuid" value="{{ Illuminate\Support\Str::uuid() }}"><p class="text-sm">Tanggal tercatat otomatis: {{ now()->format('d/m/Y H:i') }} (waktu input). Tanggal ini menentukan periode laporan aktivitas.</p><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="submitting">Ya, Tandai UTJ</x-crm.button></form></x-crm.modal>@endif @endcan</div></td></tr>@empty<tr><td colspan="9"><x-crm.empty-state title="Belum ada lead tim" description="Lead anggota aktif tim pada periode ini akan muncul di sini." /></td></tr>@endforelse</tbody></table></div>
            <x-crm.pagination :collection="$leads" :show-per-page="false" />
        </x-crm.section>
    @elseif($tab === 'agenda')
            <x-crm.section id="coordinator-team-agenda" title="Agenda Sales Tim" description="Agenda Sales aktif dalam tim dan periode terpilih." class="sales-pocketbook-screen-section">
            @if($canManageAgenda && $salesUsers->isNotEmpty())
                <x-crm.section id="coordinator-agenda-input" title="Tambah Agenda Tim" description="Buat agenda untuk Sales aktif dalam tim Anda." class="sales-pocketbook-screen-section sales-pocketbook-composer">
                    <form method="POST" action="{{ route('sales-agendas.store') }}" class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">@csrf
                        <x-crm.field label="Sales PIC" for="coordinator-agenda-sales" required><select id="coordinator-agenda-sales" class="sales-input" name="sales_user_id" x-model="sales" @change="agendaSalesChanged()" required><option value="">Pilih Sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}">{{ $sales->name }}</option>@endforeach</select></x-crm.field>
                        <x-crm.field label="Proyek" for="coordinator-agenda-project" required><select id="coordinator-agenda-project" class="sales-input" name="project_id" x-model="project" required><option value="">Pilih proyek</option><template x-for="item in projects" :key="item.id"><option :value="item.id" x-text="item.name"></option></template></select></x-crm.field>
                        <x-crm.field label="Tanggal" for="coordinator-agenda-date" required><x-crm.date-field id="coordinator-agenda-date" name="scheduled_date" :value="old('scheduled_date', now()->toDateString())" required /></x-crm.field>
                        <x-crm.field label="Kategori" for="coordinator-agenda-category" required><select id="coordinator-agenda-category" class="sales-input" name="sales_activity_category" required><option value="">Pilih kategori</option>@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option value="{{ $category }}" @selected(old('sales_activity_category') === $category)>{{ $category }}</option>@endforeach</select></x-crm.field>
                        <x-crm.field label="Judul Agenda" for="coordinator-agenda-title" required><input id="coordinator-agenda-title" class="sales-input" name="title" value="{{ old('title') }}" required></x-crm.field>
                        <x-crm.field label="Lokasi" for="coordinator-agenda-location"><input id="coordinator-agenda-location" class="sales-input" name="location" value="{{ old('location') }}"></x-crm.field>
                        <div class="flex items-end md:col-span-2 xl:col-span-2"><x-crm.button type="submit" variant="primary" accent="sales">Tambah Agenda</x-crm.button></div>
                    </form>
                </x-crm.section>
            @endif
            <!-- <th>Lokasi</th><th>Hasil</th><th>Status</th> legacy table contract -->
            @php
                $openAgendaCount = $agendas->getCollection()->filter(fn ($agenda) => ! in_array($agenda->status, ['done', 'cancelled', 'rescheduled'], true))->count();
            @endphp
            <div class="crm-table-scroll coordinator-agenda-table"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Sales PIC</th><th>Cabang</th><th>Proyek</th><th>Kategori</th><th>Agenda</th><th>Lokasi</th><th>Hasil</th><th>Bukti</th><th>Status</th></tr></thead><tbody>@forelse($agendas as $agenda)
                @php
                    [$agendaStatusLabel, $agendaStatusVariant] = $agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral'];
                @endphp
                <tr><td>{{ $agenda->scheduled_date->format('d/m/Y') }}</td><td>{{ $agenda->owner?->name }}</td><td>{{ $agenda->branch?->name }}</td><td>{{ $agenda->salesProject?->project_name }}</td><td>{{ $agenda->sales_activity_category ?: '-' }}</td><td>{{ $agenda->title }}</td><td>{{ $agenda->location ?: '-' }}</td><td>{{ $agenda->activity_result ?: '-' }}</td><td>@forelse($agenda->evidence as $evidence)@if($evidence->purged_at)<span>Bukti foto telah dipindahkan ke arsip.</span>@else<a class="font-bold underline" href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}">{{ $evidence->original_name }}</a>@endif<br>@empty-@endforelse</td><td><x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge></td></tr>@empty<tr><td colspan="10"><x-crm.empty-state title="Belum ada agenda tim" description="Agenda Sales pada periode terpilih akan tampil di sini." /></td></tr>@endforelse</tbody></table></div>
            <div class="coordinator-agenda-mobile-cards" aria-label="Agenda tim versi mobile">
                @forelse($agendas as $agenda)
                    @php
                        [$agendaStatusLabel, $agendaStatusVariant] = $agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral'];
                    @endphp
                    <article class="coordinator-agenda-card">
                        <div class="coordinator-agenda-card-heading"><strong>{{ $agenda->title }}</strong><x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge></div>
                        <dl><div><dt>Sales PIC</dt><dd>{{ $agenda->owner?->name ?: '-' }}</dd></div><div><dt>Jadwal</dt><dd>{{ $agenda->scheduled_date->locale('id')->translatedFormat('D, d M Y') }} · {{ substr((string) $agenda->start_time, 0, 5) }}</dd></div><div><dt>Lokasi</dt><dd>{{ $agenda->location ?: '-' }}</dd></div></dl>
                        <a href="{{ route('sales-agendas.show', $agenda) }}">Lihat detail agenda</a>
                    </article>
                @empty
                    <x-crm.empty-state title="Belum ada agenda tim" description="Agenda Sales pada periode terpilih akan tampil di sini." />
                @endforelse
            </div>
            @if($canManageAgenda)
                <x-crm.section id="coordinator-agenda-updates" title="Agenda perlu diperbarui" :description="$openAgendaCount.' agenda masih menunggu hasil.'" class="sales-pocketbook-screen-section">
                    <span class="coordinator-agenda-updates-count">{{ $openAgendaCount }} agenda terbuka</span>
                    <div class="grid gap-3 p-4 md:grid-cols-2">
                        @foreach($agendas as $agenda)
                            @if(!in_array($agenda->status, ['done', 'cancelled', 'rescheduled'], true))
                                <form method="POST" action="{{ route('sales-agendas.update', $agenda) }}" class="border-2 border-black bg-white p-3" id="coordinator-agenda-result-card-{{ $agenda->id }}">@csrf @method('PATCH')<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}"><strong class="block">{{ $agenda->owner?->name }} · {{ $agenda->title }}</strong><x-crm.field label="Hasil Aktivitas" for="coordinator-agenda-result-{{ $agenda->id }}" required><textarea id="coordinator-agenda-result-{{ $agenda->id }}" class="sales-input" name="activity_result" rows="2" required></textarea></x-crm.field><x-crm.button type="submit" variant="primary" accent="sales" size="sm">Tandai Selesai</x-crm.button></form>
                            @endif
                        @endforeach
                    </div>
                </x-crm.section>
            @endif
            <x-crm.pagination :collection="$agendas" :show-per-page="false" />
        </x-crm.section>
    @else
        <x-crm.section id="coordinator-report-summary" title="Ringkasan Periode" :description="$period['from']->format('d/m/Y').' - '.$period['to']->format('d/m/Y')" class="sales-pocketbook-screen-section">
            <x-slot:actions>
                @if($canExport)<x-crm.button variant="secondary" :href="route('coordinator-leads.export', $filters)">Export lead</x-crm.button>@endif
                @if($canSync)<form method="POST" action="{{ route('coordinator-leads.sync') }}">@csrf<x-crm.button type="submit" variant="secondary">Sinkron Google Sheet</x-crm.button></form>@endif
            </x-slot:actions>
            <div class="grid grid-cols-2 gap-3 p-4 lg:grid-cols-4">@foreach(['Lead Baru' => $kpi['lead_new'], 'Tatap Muka' => $kpi['face_to_face'], 'Cek Lokasi' => $kpi['site_visit'], 'UTJ' => $kpi['utj']] as $label => $count)<x-crm.card><div class="text-xs font-bold uppercase">{{ $label }}</div><strong class="text-xl">{{ $count }}</strong></x-crm.card>@endforeach</div>
            @if(array_sum($kpi) === 0)
                <div class="px-4 pb-4"><x-crm.empty-state title="Belum ada aktivitas pada periode ini." description="Metrik akan terisi setelah lead atau aktivitas terkait tercatat dalam scope dan periode yang dipilih." /></div>
            @endif
        </x-crm.section>

        @if($targetSummaries->isNotEmpty())
            <x-crm.section id="coordinator-sales-lead-target-progress" title="Target Lead {{ $targetMonth->locale('id')->translatedFormat('F Y') }}" description="Target menjadi konteks performa Sales; angka actual dihitung berdasarkan PIC Sales." class="sales-pocketbook-screen-section">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($targetSummaries as $salesId => $summary)
                        @php
                            $targetSales = $salesUsers->firstWhere('id', $salesId);
                        @endphp
                        <div class="border-2 border-black bg-white p-3">
                            <div class="flex items-start justify-between gap-3"><strong>{{ $targetSales?->name }}</strong><span class="text-xs font-bold uppercase">{{ $summary['target'] === null ? 'Belum ditetapkan' : $summary['achievement'].'%' }}</span></div>
                            <div class="mt-2 flex items-baseline gap-2"><strong class="text-2xl">{{ $summary['actual'] }}</strong><span class="text-xs uppercase">actual / {{ $summary['target'] ?? '—' }} target</span></div>
                        </div>
                    @endforeach
                </div>
            </x-crm.section>
        @endif

        <x-crm.section id="coordinator-performance" title="Performa Sales" description="Perbandingan funnel Lead untuk setiap Sales aktif dalam tim Anda." class="sales-pocketbook-screen-section">
            <div class="coordinator-report-cards">@forelse($salesRows as $row)<article><strong>{{ $row->name }}</strong><p>{{ $row->branch_name ?: '-' }} · {{ $row->project_name ?: '-' }}</p><div><span>Lead baru <b>{{ $row->lead_new }}</b></span><span>Tatap muka <b>{{ $row->face_to_face }}</b></span><span>Cek lokasi <b>{{ $row->site_visit }}</b></span><span>UTJ <b>{{ $row->utj }}</b></span></div></article>@empty<x-crm.empty-state title="Belum ada Sales pada cakupan ini." />@endforelse</div>
            <div class="crm-table-scroll coordinator-report-table"><table class="crm-data-table"><thead><tr><th>Sales</th><th>Cabang</th><th>Proyek</th><th>Lead Baru</th><th>Tatap Muka</th><th>Cek Lokasi</th><th>UTJ</th></tr></thead><tbody>@forelse($salesRows as $row)<tr><td>{{ $row->name }}</td><td>{{ $row->branch_name ?: '-' }}</td><td>{{ $row->project_name ?: '-' }}</td><td>{{ $row->lead_new }}</td><td>{{ $row->face_to_face }}</td><td>{{ $row->site_visit }}</td><td>{{ $row->utj }}</td></tr>@empty<tr><td colspan="7"><x-crm.empty-state title="Belum ada Sales pada cakupan ini." /></td></tr>@endforelse</tbody></table></div>
        </x-crm.section>
    @endif
</div>
</div>
@endsection
