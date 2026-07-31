@props([
    'titleKey' => 'id',
    'statusKey' => null,
    'statusColors' => '{}',
    'notesKey' => null,
    'creatorKey' => null,
    'commentableType' => null,
    'fields' => [],
])

<div x-show="open"
     x-cloak
     @keydown.escape.window="close()"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background: rgba(0,0,0,0.5);">
    <div @click.away="close()"
         class="relative bg-[#f5f0eb] border border-black w-full max-w-lg mx-auto max-h-[80vh] overflow-y-auto shadow-[4px_4px_0_#000] rotate-[-0.3deg]">
        <div class="absolute -top-2 -right-2 z-10 text-2xl select-none" aria-hidden="true">&#x1F4CE;</div>
        <div class="bg-[#2a2a2a] text-white px-4 py-2.5 font-mono text-xs tracking-[0.2em] uppercase flex items-center justify-between border-b border-[#d4c9b8]">
            <span x-text="task ? task.{{ $titleKey }} : 'Detail'" class="truncate mr-2"></span>
            <button @click="close()" class="text-white/70 hover:text-white text-lg leading-none shrink-0">&times;</button>
        </div>
        <div x-show="loading" class="p-6 text-center text-sm font-['Times_New_Roman'] text-gray-500">Memuat...</div>
        <div x-show="error" x-cloak class="p-6 text-center text-sm font-['Times_New_Roman']">
            <p class="font-bold text-[#c0392b]">Gagal memuat detail.</p>
            <p class="mt-1 text-gray-500">Koneksi atau data sementara tidak tersedia.</p>
            <div class="mt-4 flex items-center justify-center gap-2">
                <button @click="retry()" class="bg-white text-black px-3 py-1 text-xs font-mono font-bold border border-black hover:bg-gray-100">Coba Lagi</button>
                <button @click="close()" class="bg-white text-black px-3 py-1 text-xs font-mono font-bold border border-black hover:bg-gray-100">Tutup</button>
            </div>
        </div>
        <template x-if="!loading && !error && task">
            <div class="text-sm font-['Times_New_Roman']">
                <div class="px-5 py-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm border-b border-dashed border-[#d4c9b8]">
                    @foreach($fields as $field)
                    @php
                        $colspan = ($field['colspan'] ?? 1) == 2 ? 'col-span-2' : '';
                        $type = $field['type'] ?? 'text';
                    @endphp
                    <div class="{{ $colspan }}">
                        <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">{{ $field['label'] }}</span>
                        @if($type === 'badge')
                        <p class="mt-0.5">
                            <span x-text="task.{{ $field['key'] }} ? task.{{ $field['key'] }}.replace('_', ' ').toUpperCase() : '—'"
                                  class="border border-black px-1.5 py-0.5 text-[10px] font-mono font-bold tracking-wider"
                                  :style="'background:' + ({!! $statusColors !!})[task.{{ $field['key'] }}] || '#ccc'"></span>
                        </p>
                        @elseif($type === 'chips')
                        <div class="flex flex-wrap gap-1.5 mt-1">
                            <template x-for="name in (task.{{ $field['key'] }} || [])" :key="name">
                                <span class="border border-black bg-[#b3bd95] px-2 py-0.5 text-[11px] font-mono font-bold" x-text="name"></span>
                            </template>
                            <span x-show="!task.{{ $field['key'] }} || task.{{ $field['key'] }}.length === 0" class="text-sm text-gray-500">—</span>
                        </div>
                        @elseif($type === 'boolean')
                        <p x-text="task.{{ $field['key'] }} ? '✓' : '—'" class="mt-0.5 text-sm"></p>
                        @elseif($type === 'date')
                        <p x-text="task.{{ $field['key'] }} ? new Date(task.{{ $field['key'] }}).toLocaleDateString('id-ID') : '—'" class="mt-0.5 text-sm text-gray-900"></p>
                        @else
                        <p x-text="task.{{ $field['key'] }} || '—'" class="mt-0.5 text-sm text-gray-900"></p>
                        @endif
                    </div>
                    @endforeach
                </div>
                @if($notesKey)
                <div class="px-5 py-4 border-b border-dashed border-[#d4c9b8]">
                    <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">Catatan</span>
                    <p x-text="task.{{ $notesKey }} || '—'" class="mt-1 text-sm leading-relaxed text-gray-800"></p>
                </div>
                @endif
                <div class="px-5 py-3 flex items-center justify-between text-[10px] text-gray-500 font-mono">
                    <span x-text="'Dibuat oleh: ' + ({{ $creatorKey ? 'task.' . $creatorKey : "'—'" }} || '—')"></span>
                    <div class="flex gap-2">
                        @if($commentableType && auth()->user()->hasPermission('comments.view'))<a :href="@js(url('/comments/thread/'.$commentableType)) + '/' + task.id" class="bg-white text-[#0000ee] px-3 py-1 text-xs font-mono font-bold border border-black underline">Komentar (<span x-text="task.comments_count || 0"></span>)</a>@endif
                        <button @click="close()" class="bg-white text-black px-3 py-1 text-xs font-mono font-bold border border-black hover:bg-gray-100">Tutup</button>
                        <a :href="editUrl" class="bg-[#b3bd95] text-black px-3 py-1 text-xs font-mono font-bold border border-black hover:bg-[#9eaa7a]">Edit</a>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
