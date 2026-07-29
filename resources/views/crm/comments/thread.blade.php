@extends('layouts.crm')

@section('title', $moduleContext['title'].' - Oasis CRM')

@section('content')
<div class="mx-auto max-w-4xl">
    <header class="mb-4 border-2 border-black px-4 py-3" style="background: {{ $moduleContext['color'] }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0"><p class="font-[Helvetica] text-[10px] font-bold uppercase">{{ $moduleContext['module'] }}</p><h1 class="truncate font-['Arial_Black'] text-xl font-black uppercase">{{ $moduleContext['title'] }}</h1><p class="mt-1 truncate text-sm" title="{{ $targetLabel }}">{{ $targetLabel }}</p></div>
            <a href="{{ $backUrl }}" class="shrink-0 border-2 border-black bg-white px-3 py-2 font-[Helvetica] text-xs font-bold">Kembali</a>
        </div>
    </header>
    <x-comments.panel :commentable-type="$targetAlias" :commentable-id="$targetId" :initial-count="$commentCount" :accent="$moduleContext['color']" />
</div>
@endsection
