@extends('layouts.crm')

@section('title', 'Cabang - Oasis CRM')

@section('content')
    <x-crm.page-header color="#8c9ae0" title="Manajemen Cabang" />

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @php
            $cardColors = ['bg-[#b3bd95]', 'bg-[#d77a7a]', 'bg-[#e6915d]', 'bg-[#c0d4a7]', 'bg-[#9ab6c8]', 'bg-[#8c9ae0]', 'bg-[#b3bd95]', 'bg-[#d77a7a]'];
        @endphp
        @foreach($branches as $index => $branch)
        <div class="border-2 border-black">
            <div class="bg-white border-b-2 border-black px-3 py-1.5">
                <span class="font-[Helvetica] font-bold text-xs uppercase">{{ $branch->code }}</span>
            </div>
            <div class="{{ $cardColors[$index % count($cardColors)] }} px-3 py-3">
                <h3 class="font-['Arial_Black'] font-black text-base mb-1">{{ $branch->name }}</h3>
                <div class="text-xs font-['Times_New_Roman'] space-y-0.5">
                    <div>Task: <strong>{{ $branch->content_items_count ?? 0 }}</strong></div>
                    <div>Admin: <strong>{{ $branch->admins_count ?? 0 }}</strong></div>
                </div>
            </div>
            <div class="border-t-2 border-black bg-white px-3 py-2">
                <a href="{{ route('branches.assign', $branch->id) }}"
                   class="text-[#0000ee] underline text-xs font-[Helvetica] font-bold">
                    Atur Admin →
                </a>
            </div>
        </div>
        @endforeach
    </div>

    @if(session('success'))
    <div class="bg-[#b3bd95] border-2 border-black px-4 py-3 font-['Times_New_Roman'] text-sm mt-4">
        {{ session('success') }}
    </div>
    @endif
@endsection
