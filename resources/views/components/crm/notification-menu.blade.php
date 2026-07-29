@props(['overdueItems', 'todayItems', 'tomorrowItems', 'needsConfirmation'])

<div class="relative"
     x-data="crmNotifications(@js([
         'indexUrl' => route('notifications.index'),
         'readUrl' => route('notifications.read', ['notification' => '__ID__']),
         'readAllUrl' => route('notifications.read-all'),
         'enabled' => config('notifications.polling_enabled', true),
     ]))"
     @click.outside="open = false"
     @keydown.escape.window="open = false">
    <button type="button" @click="open = !open; if (open) refresh()"
            class="relative flex size-11 items-center justify-center hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--oasis-yellow)]"
            aria-label="Buka notifikasi" :aria-expanded="open" aria-controls="oasis-notification-menu">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        <span x-show="unreadCount > 0" x-cloak class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center border border-white bg-[var(--oasis-danger)] px-1 text-[10px] text-white" x-text="unreadCount > 9 ? '9+' : unreadCount"></span>
    </button>
    <div id="oasis-notification-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms
         class="absolute right-0 top-full z-50 mt-2 max-h-[70vh] w-[340px] max-w-[calc(100vw-1rem)] overflow-y-auto border-2 border-black bg-white text-black shadow-xl">
        <div class="sticky top-0 z-10 flex items-center justify-between bg-black px-3 py-2 text-xs font-bold uppercase text-white">
            <span>Notifikasi</span>
            <button type="button" x-show="unreadCount > 0" @click="markAllRead()" class="underline normal-case">Tandai semua dibaca</button>
        </div>
        <div x-show="loading && notifications.length === 0" class="px-3 py-4 text-center text-xs">Memuat notifikasi...</div>
        <div x-show="unavailable" class="border-b border-black bg-[#fff3b0] px-3 py-2 text-xs">Notifikasi sementara tidak tersedia. CRM tetap dapat digunakan.</div>
        <template x-for="notification in notifications" :key="notification.id">
            <button type="button" @click="markRead(notification, true)" class="block w-full border-b border-black px-3 py-2 text-left hover:bg-[#eef1ff]" :class="notification.read_at ? 'bg-white' : 'bg-[#fff3b0]'">
                <span class="block text-xs font-bold" x-text="notification.title"></span>
                <span class="block font-['Times_New_Roman'] text-xs" x-text="notification.message"></span>
                <span class="mt-1 block text-[10px] text-gray-500" x-text="notification.created_label"></span>
            </button>
        </template>
        <div x-show="!loading && notifications.length === 0" class="px-3 py-4 text-center text-xs">Belum ada notifikasi kolaborasi.</div>

        <div class="border-y-2 border-black bg-gray-100 px-3 py-2 text-xs font-bold uppercase">Pengingat</div>
        @php($hasAny = $overdueItems->isNotEmpty() || $todayItems->isNotEmpty() || $tomorrowItems->isNotEmpty() || $needsConfirmation->isNotEmpty())
        @if($hasAny)
            <x-crm.notif-section :items="$overdueItems" color="#c0392b" label="Terlewat" text-color="text-white">
                @foreach($overdueItems as $item)
                    <div class="px-3 py-2 text-sm"><div class="font-bold">[{{ strtoupper($item->item_type) }}] {{ $item->title }}</div><div class="text-xs text-red-700">Terlewat {{ $item->scheduled_date->diffForHumans() }} - {{ $item->scheduled_date->format('d M Y') }}</div></div>
                @endforeach
            </x-crm.notif-section>
            <x-crm.notif-section :items="$todayItems" color="#f1c40f" label="Hari Ini" text-color="text-black">
                @foreach($todayItems as $item)
                    <div class="px-3 py-2 text-sm"><div class="font-bold">[{{ strtoupper($item->item_type) }}] {{ $item->title }}</div><div class="text-xs text-gray-600">Jadwal: {{ $item->scheduled_date->format('d M Y') }}</div></div>
                @endforeach
            </x-crm.notif-section>
            <x-crm.notif-section :items="$tomorrowItems" color="#9ab6c8" label="Besok / H-1" text-color="text-black">
                @foreach($tomorrowItems as $item)
                    <div class="px-3 py-2 text-sm"><div class="font-bold">[{{ strtoupper($item->item_type) }}] {{ $item->title }}</div><div class="text-xs text-gray-600">Besok - {{ $item->scheduled_date->format('d M Y') }}</div></div>
                @endforeach
            </x-crm.notif-section>
            <x-crm.notif-section :items="$needsConfirmation" color="#e6915d" label="Perlu Konfirmasi" text-color="text-black">
                @foreach($needsConfirmation as $item)
                    <div class="px-3 py-2 text-sm"><div class="font-bold">[Dana] {{ $item->nama_konsumen }} - {{ $item->kav }}</div><div class="text-xs text-orange-700">Butuh konfirmasi keuangan - {{ $item->tanggal->format('d M Y') }}</div></div>
                @endforeach
            </x-crm.notif-section>
        @else
            <div class="px-4 py-6 text-center text-sm">Semua terkendali.</div>
        @endif
    </div>
</div>
