@extends('layouts.crm')

@section('title', 'Konsumen Progress - Oasis CRM')

@section('content')
    <x-crm.page-header
        variant="canonical"
        title="Konsumen Progress"
        eyebrow="Sales"
        description="Pemantauan progress konsumen per cabang: BI Checking, PSJB, Pemberkasan, Proses Bank, PPJB Dev, Akad, dan BAST."
    >
        <x-slot:actions>
            @if($selectedBranch)
            <x-crm.status-badge variant="info">Cabang: {{ $selectedBranch->code }}</x-crm.status-badge>
            @endif
        </x-slot:actions>
    </x-crm.page-header>
    <x-crm.page-presence page-key="konsumen-progress" :branch-id="$selectedBranchId" />

    <x-crm.toolbar label="Filter dan aksi konsumen progress" class="mb-3">
        @if(isset($branches) && $branches->count() > 1)
        <form method="GET" action="{{ route('konsumen-progress.index') }}" class="flex items-center gap-2">
            <label for="kp-branch" class="font-[Helvetica] text-xs font-bold uppercase">Cabang:</label>
            <select id="kp-branch" name="branch_id" onchange="this.form.submit()" class="border-2 border-black bg-white px-3 py-1.5 text-sm font-['Times_New_Roman']">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }} @if(str_contains(mb_strtolower($b->name), 'pusat')) style="color:#b8860b;font-weight:700;background:#fff3b0" @endif>{{ $b->name }} ({{ $b->code }})</option>
                @endforeach
            </select>
        </form>
        @elseif($selectedBranch)
        <span class="font-[Helvetica] text-sm font-bold uppercase">Cabang: {{ $selectedBranch->code }} — {{ $selectedBranch->name }}</span>
        @endif
        <x-slot:actions>
            <x-crm.sync-control module-key="konsumen-progress" module-name="Sinkronisasi Konsumen Progress" :scope-name="$selectedBranch?->name ?? ''" :sync-url="route('konsumen-progress.sync')" :status-url="route('konsumen-progress.sync-status', ['branch_id' => $selectedBranchId])" :status="$syncStatus" :branch-id="$selectedBranchId" :can-sync="$canSync" />
        </x-slot:actions>
    </x-crm.toolbar>

    @if($selectedBranch)
    <x-crm.sync-status-panel module-key="konsumen-progress" :scope-name="$selectedBranch->name" :branch-id="$selectedBranchId" :status="$syncStatus" :is-stale="$isStale" />
    @endif

    @if(!empty($errors))
    <x-crm.alert variant="error" title="Gagal memuat beberapa stage" class="mb-4">
        <ul class="list-disc pl-5 mt-1">
            @foreach($errors as $err)
            <li>{{ $err }}</li>
            @endforeach
        </ul>
    </x-crm.alert>
    @endif

    @php
        $stages = [
            'bi_checking' => ['label' => 'BI Checking', 'color' => '#9ab6c8'],
            'PSJB' => ['label' => 'PSJB', 'color' => '#e6915d'],
            'pemberkasan' => ['label' => 'Pemberkasan', 'color' => '#b3bd95'],
            'proses_bank' => ['label' => 'Proses Bank', 'color' => '#f1c40f'],
            'ppjb_dev' => ['label' => 'PPJB Dev', 'color' => '#8c9ae0'],
            'akad' => ['label' => 'Akad', 'color' => '#5d8e8e'],
            'bast' => ['label' => 'BAST', 'color' => '#c0d4a7'],
        ];

        $darkTextStages = ['proses_bank', 'ppjb_dev', 'akad'];
        $defaultStage = 'bast';
        foreach (array_reverse(array_keys($stages)) as $key) {
            if (!empty($pipeline[$key] ?? [])) {
                $defaultStage = $key;
                break;
            }
        }

        $allItemsFlat = [];
        foreach ($stages as $key => $cfg) {
            foreach ($pipeline[$key] ?? [] as $item) {
                $allItemsFlat[] = [
                    'nama' => $item['nama'],
                    'kavling' => $item['kavling'],
                    'phone' => $item['phone'] ?? null,
                    'stage_key' => $key,
                    'stage_label' => $cfg['label'],
                    'stage_color' => $cfg['color'],
                ];
            }
        }
    @endphp

<script>window.__kpItems = @json($allItemsFlat);</script>

    @if($selectedBranch && $selectedBranch->sheet_id)
    <div x-data="{
        stage: '{{ $defaultStage }}',
        searchQuery: '',
        allItems: window.__kpItems,
        get hasQuery() { return this.searchQuery.trim().length > 0; },
        get groupedResults() {
            const q = this.searchQuery.toLowerCase().trim();
            if (!q) return {};
            const matched = this.allItems.filter(item =>
                item.nama.toLowerCase().includes(q) || item.kavling.toLowerCase().includes(q)
            );
            const groups = {};
            matched.forEach(item => {
                if (!groups[item.stage_key]) {
                    groups[item.stage_key] = { label: item.stage_label, color: item.stage_color, items: [] };
                }
                groups[item.stage_key].items.push(item);
            });
            return groups;
        },
        get totalResults() {
            return Object.values(this.groupedResults).reduce((sum, g) => sum + g.items.length, 0);
        },
        get resultMessage() {
            const q = this.searchQuery.trim();
            if (!q) return '';
            return 'Menampilkan ' + this.totalResults + ' hasil untuk &quot;' + q + '&quot;';
        }
    }">
        <style>[x-cloak] { display: none !important; }</style>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <input type="text" x-model="searchQuery" placeholder="Cari nama atau kavling..." aria-label="Cari konsumen progress berdasarkan nama atau kavling"
                   class="min-w-0 grow border-2 border-black bg-white px-3 py-1.5 text-sm sm:w-64 font-['Times_New_Roman']">
            <button type="button" x-show="hasQuery" @click="searchQuery = ''"
                    class="text-sm font-[Helvetica] font-bold underline hover:text-[#5d8e8e]">Clear</button>
            <span x-show="hasQuery" class="text-sm font-['Times_New_Roman'] text-gray-600" x-text="resultMessage"></span>
        </div>

        <div x-show="!hasQuery">
        <div role="group" aria-label="Tahap konsumen" class="tabs-scroll crm-horizontal-tabs mb-2 flex flex-nowrap border-b-2 border-black" data-horizontal-tabs>
            @foreach($stages as $key => $cfg)
            @php $count = count($pipeline[$key] ?? []); @endphp
            <button type="button" @click="stage = '{{ $key }}'"
                    :aria-pressed="stage === '{{ $key }}'"
                    :class="stage === '{{ $key }}' ? 'border-b-transparent' : 'bg-white'"
                    :style="stage === '{{ $key }}' ? 'background-color: {{ $cfg['color'] }}; color: {{ in_array($key, $darkTextStages) ? 'white' : 'black' }};' : 'color: black;'"
                    class="relative mb-[-2px] z-10 px-3 py-2 border-2 border-black text-[10px] sm:text-xs font-[Helvetica] font-bold uppercase whitespace-nowrap flex items-center gap-1.5 cursor-pointer shrink-0"
                    aria-label="Lihat konsumen tahap {{ $cfg['label'] }}">
                <span>{{ $cfg['label'] }}</span>
                <span class="bg-[#c0392b] text-white text-[10px] font-[Helvetica] font-bold border border-white flex items-center justify-center min-w-5 h-5 px-1">{{ $count }}</span>
            </button>
            @endforeach
        </div>

        @foreach($stages as $key => $cfg)
        @php $items = $pipeline[$key] ?? []; @endphp
        <div x-show="stage === '{{ $key }}'"
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100">
            @if(count($items) > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                @foreach($items as $item)
                <x-crm.card padding="sm">
                    <div class="font-['Times_New_Roman'] text-sm font-bold truncate" title="{{ $item['nama'] }}">{{ $item['nama'] }}</div>
                    <div class="mt-0.5 truncate font-[Helvetica] text-[11px] text-gray-600" title="{{ $item['kavling'] }}">{{ $item['kavling'] }}</div>
                    @if(!empty($item['phone']))
                    <div class="mt-0.5 font-[Helvetica] text-[11px] text-gray-600">
                        <a href="tel:{{ $item['phone'] }}" class="underline hover:text-[#5d8e8e]">{{ $item['phone'] }}</a>
                    </div>
                    @endif
                </x-crm.card>
                @endforeach
            </div>
            @else
            <x-crm.empty-state title="Belum ada konsumen pada tahap {{ $cfg['label'] }}." description="Konsumen akan muncul di sini setelah tersedia di data tahap tersebut." />
            @endif
        </div>
        @endforeach
    </div>

        <div x-show="hasQuery">
            <template x-if="totalResults === 0">
                <x-crm.empty-state title="Tidak ada konsumen ditemukan." description="Coba kata kunci lain untuk nama konsumen atau kavling." />
            </template>
            <template x-for="(group, key) in groupedResults" :key="key">
                <div class="mb-4">
                    <div class="mb-2 flex items-center gap-2 border-2 border-black px-3 py-1 font-[Helvetica] text-xs font-bold uppercase"
                         :style="'background-color: ' + group.color + '; color: ' + (['proses_bank','ppjb_dev','akad'].includes(key) ? 'white' : 'black')">
                        <span x-text="group.label"></span>
                        <span class="bg-[#c0392b] px-1.5 py-0.5 text-white text-[10px]" x-text="group.items.length"></span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <template x-for="item in group.items" :key="item.kavling">
                            <x-crm.card padding="sm">
                                <div class="font-['Times_New_Roman'] text-sm font-bold truncate" x-text="item.nama" :title="item.nama"></div>
                                <div class="mt-0.5 truncate font-[Helvetica] text-[11px] text-gray-600" x-text="item.kavling" :title="item.kavling"></div>
                                <template x-if="item.phone">
                                    <div class="mt-0.5 font-[Helvetica] text-[11px] text-gray-600">
                                        <a :href="'tel:' + item.phone" class="underline hover:text-[#5d8e8e]" x-text="item.phone"></a>
                                    </div>
                                </template>
                            </x-crm.card>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
    @else
    <x-crm.empty-state
        title="{{ Auth::user()->canViewAllBranches() ? 'Silakan pilih cabang terlebih dahulu.' : 'Database branch belum tersedia.' }}"
        description="{{ Auth::user()->canViewAllBranches() ? 'Pilih cabang untuk melihat progress konsumen.' : 'Hubungi superadmin agar data branch dapat disinkronkan.' }}"
    />
    @endif
@endsection
