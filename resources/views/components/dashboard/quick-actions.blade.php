@props(['actions'])

@if(count($actions) > 0)
    <div class="dashboard-quick-actions" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }">
        <button type="button" x-ref="trigger" @click="open = !open"
                class="dashboard-quick-actions-trigger"
                aria-haspopup="menu" :aria-expanded="open" aria-controls="dashboard-quick-actions-menu">
            <span class="dashboard-quick-action-mark" aria-hidden="true">+</span>
            <span>Aksi Cepat</span>
            <svg class="ml-auto size-4 transition-transform duration-150" :class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="m5 7 5 5 5-5" />
            </svg>
        </button>
        <div id="dashboard-quick-actions-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms
             class="dashboard-quick-actions-menu" role="menu">
            @foreach($actions as $action)
                <a href="{{ $action['url'] }}" class="dashboard-quick-action" data-accent="{{ $action['accent'] ?? 'neutral' }}" role="menuitem">
                    <span class="dashboard-quick-action-mark" aria-hidden="true">+</span>
                    <span>{{ $action['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
