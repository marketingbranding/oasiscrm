@extends('layouts.crm')

@section('title', 'Konsumen Progress - Oasis CRM')

@section('content')
    <div class="bg-[#5d8e8e] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Konsumen Progress</h1>
    </div>

    @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('konsumen-progress.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <div class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }} @if(str_contains(mb_strtolower($b->name), 'pusat')) style="color:#b8860b;font-weight:700;background:#fff3b0" @endif>{{ $b->name }} ({{ $b->code }})</option>
                @endforeach
            </select>
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <button type="submit" form="konsumen-progress-sync-form" class="bg-white text-black px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    Sync Sekarang
                </button>
            </div>
        </form>
    </div>
    @elseif($selectedBranch)
    <div class="bg-[#fcc20f] border-2 border-black px-4 py-2 mb-4 flex items-center gap-3">
        <span class="font-['Arial_Black'] font-black text-lg uppercase">Cabang: {{ $selectedBranch->code }}</span>
        <button type="submit" form="konsumen-progress-sync-form" class="bg-white text-black px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100 ml-auto">
            Sync Sekarang
        </button>
    </div>
    @endif

    @if($selectedBranch)
    <form id="konsumen-progress-sync-form" method="POST" action="{{ route('konsumen-progress.sync') }}" class="hidden">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
    </form>

    <div class="border-2 border-black bg-white px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
        <div>
            <strong class="font-bold">Status Sync:</strong>
            @if($syncStatus?->status === 'success')
                <span>Terakhir sync {{ $syncStatus->finished_at?->format('d M Y H:i') }}</span>
            @elseif($syncStatus?->status === 'failed')
                <span class="text-[#c0392b]">Sync terakhir gagal: {{ $syncStatus->message }}</span>
            @elseif($syncStatus?->status === 'running')
                <span>Sedang sync...</span>
            @else
                <span>Belum pernah sync. Klik Sync Sekarang.</span>
            @endif
        </div>
        @if($isStale)
            <span class="inline-block bg-[#fcc20f] border-2 border-black px-2 py-1 font-[Helvetica] text-[10px] font-bold uppercase">Data perlu diperbarui</span>
        @endif
    </div>
    @endif

    @if(!empty($errors))
    <div class="bg-[#d77a7a] border-2 border-black px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm">
        <strong class="font-bold">Gagal memuat beberapa stage:</strong>
        <ul class="list-disc pl-5 mt-1">
            @foreach($errors as $err)
            <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
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
        <style>
            [x-cloak] { display: none !important; }
            .tabs-scroll { scrollbar-width: none; -ms-overflow-style: none; }
            .tabs-scroll::-webkit-scrollbar { display: none; }
        </style>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <input type="text" x-model="searchQuery" placeholder="Cari nama atau kavling..."
                   class="border-2 border-black px-3 py-1.5 text-sm w-full sm:w-64 font-['Times_New_Roman'] bg-white">
            <button x-show="hasQuery" @click="searchQuery = ''"
                    class="text-sm font-['Helvetica'] underline hover:text-[#5d8e8e]">Clear</button>
            <span x-show="hasQuery" class="text-sm font-['Times_New_Roman'] text-gray-600"
                  x-text="resultMessage"></span>
        </div>

        <div x-show="!hasQuery">
        <div class="flex flex-wrap border-b-2 border-black mb-2">
            @foreach($stages as $key => $cfg)
            @php $count = count($pipeline[$key] ?? []); @endphp
            <button @click="stage = '{{ $key }}'"
                    :class="stage === '{{ $key }}' ? 'border-b-transparent' : 'bg-white'"
                    :style="stage === '{{ $key }}' ? 'background-color: {{ $cfg['color'] }}; color: {{ in_array($key, $darkTextStages) ? 'white' : 'black' }};' : 'color: black;'"
                    class="relative mb-[-2px] z-10 px-3 py-2 border-2 border-black text-[10px] sm:text-xs font-[Helvetica] font-bold uppercase whitespace-nowrap flex items-center gap-1.5 cursor-pointer shrink-0">
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
                <div class="border-2 border-black bg-white px-3 py-2 hover:bg-gray-50">
                    <div class="font-['Times_New_Roman'] font-bold text-sm truncate" title="{{ $item['nama'] }}">{{ $item['nama'] }}</div>
                    <div class="font-['Helvetica'] text-[11px] text-gray-600 mt-0.5 truncate" title="{{ $item['kavling'] }}">{{ $item['kavling'] }}</div>
                    @if(!empty($item['phone']))
                    <div class="font-['Helvetica'] text-[11px] text-gray-600 mt-0.5">
                        <a href="tel:{{ $item['phone'] }}" class="underline hover:text-[#5d8e8e]">{{ $item['phone'] }}</a>
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <div class="border-2 border-black bg-white px-4 py-8 text-center">
                <p class="text-sm text-gray-400 font-['Times_New_Roman'] italic">—</p>
            </div>
            @endif
        </div>
        @endforeach
    </div>

        <div x-show="hasQuery">
            <template x-if="totalResults === 0">
                <div class="border-2 border-black bg-white px-6 py-8 text-center">
                    <p class="font-['Times_New_Roman'] text-sm italic">Tidak ada konsumen ditemukan.</p>
                </div>
            </template>
            <template x-for="(group, key) in groupedResults" :key="key">
                <div class="mb-4">
                    <div class="font-['Helvetica'] text-xs font-bold uppercase px-3 py-1 border-2 border-black mb-2 flex items-center gap-2"
                         :style="'background-color: ' + group.color + '; color: ' + (['proses_bank','ppjb_dev','akad'].includes(key) ? 'white' : 'black')">
                        <span x-text="group.label"></span>
                        <span class="bg-[#c0392b] text-white text-[10px] px-1.5 py-0.5" x-text="group.items.length"></span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <template x-for="item in group.items" :key="item.kavling">
                            <div class="border-2 border-black bg-white px-3 py-2 hover:bg-gray-50">
                                <div class="font-['Times_New_Roman'] font-bold text-sm truncate" x-text="item.nama" :title="item.nama"></div>
                                <div class="font-['Helvetica'] text-[11px] text-gray-600 mt-0.5 truncate" x-text="item.kavling" :title="item.kavling"></div>
                                <template x-if="item.phone">
                                    <div class="font-['Helvetica'] text-[11px] text-gray-600 mt-0.5">
                                        <a :href="'tel:' + item.phone" class="underline hover:text-[#5d8e8e]" x-text="item.phone"></a>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
    @else
    <div class="border-2 border-black bg-white px-6 py-8 text-center">
        <p class="font-['Times_New_Roman'] text-sm">
            @if(Auth::user()->canViewAllBranches())
                Silakan pilih cabang terlebih dahulu.
            @else
                Database branch belum tersedia. Hubungi superadmin.
            @endif
        </p>
    </div>
    @endif
@endsection
