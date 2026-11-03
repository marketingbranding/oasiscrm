@php
    $agendaStatuses = [
        'planned' => ['Menunggu', 'pending'],
        'confirmed' => ['Dikonfirmasi', 'success'],
        'done' => ['Selesai', 'success'],
        'cancelled' => ['Dibatalkan', 'inactive'],
        'rescheduled' => ['Ditunda ke besok', 'warning'],
    ];
@endphp

<section class="sales-agenda-history" aria-labelledby="sales-agenda-history-title">
    <header class="sales-agenda-history-header">
        <div>
            <span class="sales-agenda-history-eyebrow">Agenda pribadi</span>
            <h2 id="sales-agenda-history-title">Semua agenda</h2>
            <p>Agenda Anda dari tanggal sebelumnya, hari ini, dan rencana mendatang.</p>
        </div>
        <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $agendaDate->toDateString()]) }}">Kembali ke hari terpilih</a>
    </header>

    @if($allAgendas->isEmpty())
        <x-crm.empty-state title="Belum ada agenda" description="Agenda pribadi Anda akan tampil di sini setelah dibuat." />
    @else
        <div class="sales-agenda-history-list" role="list">
            @foreach($allAgendas as $agenda)
                @php([$agendaStatusLabel, $agendaStatusVariant] = $agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral'])
                <article class="sales-agenda-history-compact-row" role="listitem">
                    <div class="sales-agenda-history-compact-main">
                        <div class="sales-agenda-history-compact-time" aria-label="Jadwal agenda">
                            <strong>{{ $agenda->start_time && $agenda->end_time ? substr((string) $agenda->start_time, 0, 5).'–'.substr((string) $agenda->end_time, 0, 5) : 'Fleksibel' }}</strong>
                            @if($agenda->duration_minutes)<small>{{ $agenda->duration_minutes }} menit</small>@endif
                        </div>
                        <div class="sales-agenda-history-compact-content">
                            <div class="sales-agenda-history-compact-meta">
                                <span>{{ $agenda->scheduled_date->format('d/m/Y') }}</span>
                                <span class="sales-agenda-history-compact-category">{{ $agenda->sales_activity_category ?: 'Aktivitas Sales' }}</span>
                            </div>
                            <h3>{{ $agenda->title }}</h3>
                            <div class="sales-agenda-history-compact-status">
                                <x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge>
                            </div>
                            <a class="sales-agenda-history-detail" href="{{ route('sales-agendas.show', [$agenda, 'return_view' => 'all', 'return_work_date' => $agendaDate->toDateString()]) }}">Buka detail agenda</a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        <x-crm.pagination :collection="$allAgendas" :show-per-page="false" />
    @endif
</section>
