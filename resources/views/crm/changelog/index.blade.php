@extends('layouts.crm')

@section('title', 'Changelog - Oasis CRM')

@section('content')
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6 flex items-center justify-between">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Changelog</h1>
        @if(Auth::user()->isSuperadmin())
        <a href="{{ route('changelogs.create') }}" class="bg-[#8c9ae0] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#7a88ce]">
            + Tambah
        </a>
        @endif
    </div>

    @if($grouped->isEmpty())
    <div class="border-2 border-black bg-white px-6 py-8 text-center">
        <p class="font-['Times_New_Roman'] text-sm">Belum ada changelog.</p>
    </div>
    @endif

    @foreach($grouped as $date => $items)
    <div class="mb-6">
        <div class="bg-black text-white px-3 py-1.5 font-[Helvetica] font-bold text-xs uppercase inline-block mb-3 border-2 border-black">
            {{ $date }}
        </div>

        @foreach($items as $log)
        @php
            $catColors = [
                'added' => ['bg' => 'bg-[#b3bd95]', 'label' => 'ADDED'],
                'fixed' => ['bg' => 'bg-[#5d8e8e]', 'label' => 'FIXED'],
                'changed' => ['bg' => 'bg-[#fcc20f]', 'label' => 'CHANGED'],
                'removed' => ['bg' => 'bg-[#d77a7a]', 'label' => 'REMOVED'],
            ];
            $style = $catColors[$log->category] ?? ['bg' => 'bg-gray-300', 'label' => strtoupper($log->category)];
        @endphp
        <div class="border-2 border-black bg-white mb-2">
            <div class="flex items-start gap-3 px-4 py-3">
                <span class="{{ $style['bg'] }} text-black px-2 py-0.5 text-[10px] font-[Helvetica] font-bold border-2 border-black shrink-0 mt-0.5">
                    {{ $style['label'] }}
                </span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="font-[Helvetica] font-bold text-sm">{{ $log->title }}</h3>
                        @if($log->version)
                            <span class="text-[10px] font-[Helvetica] font-bold text-gray-500 border border-gray-300 px-1.5 py-0.5">v{{ $log->version }}</span>
                        @endif
                    </div>
                    @if($log->description)
                    <p class="font-['Times_New_Roman'] text-sm mt-1 whitespace-pre-wrap">{{ $log->description }}</p>
                    @endif
                    <div class="flex items-center gap-3 mt-1.5 text-[10px] font-[Helvetica] text-gray-400">
                        <span>{{ $log->creator?->name ?? 'System' }}</span>
                        <span>{{ $log->created_at->format('H:i') }}</span>
                        @if(Auth::user()?->isSuperadmin())
                        <div class="flex gap-2 ml-auto">
                            <a href="{{ route('changelogs.edit', $log) }}" class="underline text-gray-500 hover:text-black">Edit</a>
                            <form method="POST" action="{{ route('changelogs.destroy', $log) }}" class="inline" onsubmit="return confirm('Hapus changelog ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="underline text-gray-500 hover:text-[#c0392b]">Hapus</button>
                            </form>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @endforeach
@endsection
