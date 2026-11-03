<div class="sales-tour" x-data="salesPocketbookTour({{ count($steps) }})" x-init="start()" @keydown="handleKeydown($event)" role="dialog" aria-modal="true" aria-labelledby="sales-tour-title" aria-describedby="sales-tour-description">
    <div class="sales-tour-scrim" aria-hidden="true"></div>
    <div class="sales-tour-spotlight" data-tour-spotlight aria-hidden="true"></div>
    <div class="sales-tour-card" data-tour-card tabindex="-1">
        @foreach($steps as $index => $item)
            <section class="sales-tour-step" data-tour-step="{{ $index + 1 }}" x-show="step === {{ $index + 1 }}" x-cloak :aria-hidden="step !== {{ $index + 1 }}">
                <span class="sales-tour-count">TOUR {{ $index + 1 }} DARI {{ count($steps) }}</span>
                <h2 id="sales-tour-title">{{ $index === 0 ? 'Selamat datang, '.Auth::user()->name : $item['title'] }}</h2>
                <p id="sales-tour-description">{{ $item['description'] }}</p>
                <div class="sales-tour-actions">
                    <form method="POST" action="{{ route('sales-pocketbook.onboarding.skip') }}">@csrf<button type="submit" class="sales-tour-skip">Lewati tour</button></form>
                    @if($index + 1 < count($steps))
                        <button type="button" class="sales-tour-next" @click="next()">{{ $index === 0 ? 'Mulai tour' : 'Lanjut' }}</button>
                    @else
                        <form method="POST" action="{{ route('sales-pocketbook.onboarding.complete') }}">@csrf<input type="hidden" name="current_step" value="{{ count($steps) }}"><button type="submit" class="sales-tour-next">Mulai bekerja</button></form>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
