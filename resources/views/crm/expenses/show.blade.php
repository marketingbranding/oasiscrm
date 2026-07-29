@extends('layouts.crm')

@section('title', 'Detail Pengeluaran - Oasis CRM')

@section('content')
<div x-data="{ cancelOpen: {{ $errors->has('cancellation_reason') ? 'true' : 'false' }}, cancelling: false }">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2 border-2 border-black bg-[#b3bd95] px-4 py-3">
        <h1 class="font-['Arial_Black'] text-xl font-black uppercase">Detail Pengeluaran #{{ $expense->id }}</h1>
        <div class="flex gap-2"><a href="{{ route('expenses.index') }}" class="border-2 border-black bg-white px-3 py-2 font-bold">Kembali</a>@if($expense->status === \App\Models\Expense::STATUS_ACTIVE)<a href="{{ route('expenses.edit', $expense) }}" class="border-2 border-black bg-white px-3 py-2 font-bold text-[#0000ee] underline">Edit</a><button type="button" @click="cancelOpen=true" class="border-2 border-black bg-[#c0392b] px-3 py-2 font-bold text-white">Batalkan</button>@endif</div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section class="border-2 border-black bg-white lg:col-span-2">
            <h2 class="bg-black px-3 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">Informasi Pengeluaran</h2>
            <dl class="grid grid-cols-1 md:grid-cols-2">
                @foreach([
                    'Tanggal Pengeluaran' => $expense->expense_date->format('d M Y'), 'Cabang' => $expense->branch?->name,
                    'Proyek' => $expense->project?->project_name ?? '—', 'Kategori' => $expense->category?->name,
                    'Jumlah' => $expense->formattedAmount(), 'Deskripsi' => $expense->description,
                    'Vendor / Penerima' => $expense->vendor_name ?: '—', 'Metode Pembayaran' => \App\Models\Expense::PAYMENT_METHODS[$expense->payment_method] ?? '—',
                    'Nomor Referensi' => $expense->reference_number ?: '—', 'Status' => $expense->status === \App\Models\Expense::STATUS_CANCELLED ? 'Dibatalkan' : 'Aktif',
                ] as $label => $value)<div class="border-b-2 border-black p-3 md:border-r-2"><dt class="font-[Helvetica] text-[10px] font-bold uppercase text-gray-500">{{ $label }}</dt><dd class="mt-1 break-words font-bold">{{ $value }}</dd></div>@endforeach
                <div class="border-b-2 border-black p-3 md:col-span-2"><dt class="font-[Helvetica] text-[10px] font-bold uppercase text-gray-500">Catatan</dt><dd class="mt-1 whitespace-pre-wrap">{{ $expense->notes ?: '—' }}</dd></div>
                @if($expense->status === \App\Models\Expense::STATUS_CANCELLED)<div class="bg-[#fff3b0] p-3 md:col-span-2"><dt class="font-[Helvetica] text-[10px] font-bold uppercase">Alasan Pembatalan</dt><dd class="mt-1 whitespace-pre-wrap">{{ $expense->cancellation_reason }}</dd><p class="mt-2 text-xs">Dibatalkan oleh {{ $expense->cancelledBy?->name ?? '—' }} pada {{ $expense->cancelled_at?->format('d M Y H:i') }}</p></div>@endif
            </dl>
        </section>
        <section class="border-2 border-black bg-white">
            <h2 class="bg-black px-3 py-2 font-[Helvetica] text-xs font-bold uppercase text-white">Aktivitas</h2>
            <div class="divide-y-2 divide-black">
                @forelse($expense->activities->sortByDesc('created_at') as $activity)<div class="p-3"><p class="text-sm">{{ $activity->description }}</p><p class="mt-1 text-xs text-gray-500">{{ $activity->causer?->name ?? 'Sistem' }} · {{ $activity->created_at->format('d M Y H:i') }}</p></div>@empty<div class="p-4 text-sm italic">Belum ada aktivitas.</div>@endforelse
            </div>
        </section>
    </div>

    @if(auth()->user()->hasPermission('comments.view'))
        <div class="mt-4"><x-comments.panel commentable-type="expense" :commentable-id="$expense->id" :initial-count="$expense->comments_count" /></div>
    @endif

    <div x-show="cancelOpen" x-cloak x-trap.noscroll="cancelOpen" role="dialog" aria-modal="true" aria-labelledby="cancel-expense-title" class="fixed inset-0 z-[900] flex items-center justify-center bg-black/70 px-4" @keydown.escape.window="cancelOpen=false">
        <form method="POST" action="{{ route('expenses.cancel', $expense) }}" class="w-full max-w-lg border-2 border-black bg-white" @click.outside="cancelOpen=false" @submit="if (cancelling) { $event.preventDefault() } else { cancelling = true }">
            @csrf @method('PATCH')
            <input type="hidden" name="expected_updated_at" value="{{ old('expected_updated_at', app(\App\Services\OptimisticLockService::class)->token($expense)) }}">
            <div id="cancel-expense-title" class="bg-[#c0392b] px-4 py-2 font-[Helvetica] text-sm font-bold uppercase text-white">Batalkan Pengeluaran</div>
            <div class="p-4"><p class="mb-3 text-sm">Pengeluaran tidak akan dihapus dan tetap tersimpan dalam riwayat.</p><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Alasan Pembatalan</label><textarea name="cancellation_reason" maxlength="1000" rows="5" required class="w-full rounded-none border-2 border-black p-3">{{ old('cancellation_reason') }}</textarea>@error('cancellation_reason')<p class="mt-1 text-xs font-bold text-[#c0392b]">{{ $message }}</p>@enderror</div>
            <div class="flex gap-2 border-t-2 border-black bg-gray-100 p-4"><button :disabled="cancelling" class="border-2 border-black bg-[#c0392b] px-4 py-2 font-bold text-white disabled:opacity-50">Konfirmasi Pembatalan</button><button type="button" @click="cancelOpen=false" class="border-2 border-black bg-white px-4 py-2 font-bold">Kembali</button></div>
        </form>
    </div>
</div>
@endsection
