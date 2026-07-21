@extends('layouts.crm')

@section('title', 'System Health - Oasis CRM')

@section('content')
<div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6">
    <h1 class="font-['Arial_Black'] font-black text-xl uppercase">System Health</h1>
</div>

<div class="space-y-5">
    @foreach($sections as $section => $checks)
    <section class="border-2 border-black bg-white">
        <h2 class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">{{ $section }}</h2>
        <div class="divide-y-2 divide-black">
            @foreach($checks as $check)
            <div class="flex flex-col sm:flex-row sm:items-center gap-2 px-3 py-2 text-sm">
                <span class="inline-block border-2 border-black px-2 py-1 font-[Helvetica] text-[10px] font-bold uppercase {{ $check['status'] === 'pass' ? 'bg-[#b3bd95]' : ($check['status'] === 'warning' ? 'bg-[#fcc20f]' : 'bg-[#d77a7a]') }}">
                    {{ $check['status'] }}
                </span>
                <strong class="font-[Helvetica] text-xs sm:w-52">{{ $check['label'] }}</strong>
                <span class="font-['Times_New_Roman']">{{ $check['message'] }}</span>
            </div>
            @endforeach
        </div>
    </section>
    @endforeach
</div>
@endsection
