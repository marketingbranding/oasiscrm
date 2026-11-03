@php
    $isAllAgendas = ($agendaView ?? 'day') === 'all';
    $today = \Carbon\CarbonImmutable::today(config('app.timezone'));
@endphp

@if(! $isAllAgendas)
    <nav class="sales-week-navigation" data-agenda-week-navigation aria-label="Navigasi minggu agenda">
        <div class="sales-week-navigation-toolbar">
            <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $agendaWeek['previous_date']->toDateString()]) }}" class="sales-week-navigation-control" data-week-previous aria-label="Minggu sebelumnya">
                <span aria-hidden="true">←</span><span class="sr-only">Minggu sebelumnya</span>
            </a>
            <div class="sales-week-navigation-range" aria-live="polite">
                <span>Minggu agenda</span>
                <strong>{{ $agendaWeek['start']->locale('id')->translatedFormat('d M') }}–{{ $agendaWeek['end']->locale('id')->translatedFormat('d M Y') }}</strong>
            </div>
            <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $agendaWeek['next_date']->toDateString()]) }}" class="sales-week-navigation-control" data-week-next aria-label="Minggu berikutnya">
                <span aria-hidden="true">→</span><span class="sr-only">Minggu berikutnya</span>
            </a>
        </div>

        <ul class="sales-week-navigation-days" role="list" aria-label="Tanggal minggu agenda">
            @foreach($agendaWeek['days'] as $day)
                @php
                    $isSelected = $day['date']->isSameDay($agendaDate);
                    $isToday = $day['date']->isSameDay($today);
                    $isWeekend = $day['date']->isWeekend();
                    $dayName = $day['date']->locale('id')->translatedFormat('l');
                    $dayAriaLabel = $dayName.', '.$day['date']->locale('id')->translatedFormat('d F Y');
                    if (! $day['has_plan']) {
                        $dayAriaLabel .= ', belum ada rencana';
                    }
                    if ($day['has_incomplete_agenda']) {
                        $dayAriaLabel .= ', '.$day['incomplete_count'].' agenda belum selesai';
                    }
                @endphp
                <li class="sales-week-navigation-day-item" role="listitem">
                    @if($isToday)<span id="sales-week-day-today-{{ $day['date']->toDateString() }}" class="sr-only">Hari ini</span>@endif
                    <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $day['date']->toDateString()]) }}" @class(['sales-week-navigation-day', 'is-active' => $isSelected, 'is-weekend' => $isWeekend]) data-week-day="{{ $day['date']->toDateString() }}" data-week-day-state="{{ ! $day['has_plan'] ? 'empty' : ($day['has_incomplete_agenda'] ? 'attention' : 'ready') }}" data-week-day-today="{{ $isToday ? 'true' : 'false' }}" data-week-day-weekend="{{ $isWeekend ? 'true' : 'false' }}" aria-label="{{ $dayAriaLabel }}" @if($isToday) aria-describedby="sales-week-day-today-{{ $day['date']->toDateString() }}" @endif @if($isSelected) aria-current="date" @endif>
                    <span class="sales-week-navigation-day-name">{{ $day['day_label'] }}</span>
                    <strong>{{ $day['date_label'] }}</strong>
                    <span class="sales-week-navigation-day-status" aria-hidden="true">
                        @if(! $day['has_plan'])
                            <span class="sales-week-navigation-empty-mark"></span>
                        @endif
                        @if($day['has_incomplete_agenda'])
                            <span class="sales-week-navigation-alert">!</span>
                        @endif
                    </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
