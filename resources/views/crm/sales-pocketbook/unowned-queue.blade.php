@extends('layouts.crm')

@section('title', 'Antrean Lead - Oasis CRM')

@section('content')
<div class="space-y-4">
    <x-crm.page-header variant="canonical" eyebrow="BUKU SAKU SALES" title="Antrean Lead" description="Lead tanpa pemilik aktif menunggu dialihkan ke Sales.">
        <x-slot:actions><x-crm.button variant="secondary" :href="route('sales-pocketbook.index')">Kembali ke Buku Saku</x-crm.button></x-slot:actions>
    </x-crm.page-header>

    <x-crm.section id="unowned-lead-queue" title="Lead tanpa pemilik aktif" :description="$queue->count().' entri terbuka'">
        <div class="space-y-3">
            @forelse($queue as $item)
                <div class="border-2 border-black bg-white p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <strong>{{ $item->lead?->customer_name }}</strong>
                        <span class="text-sm">{{ $item->lead?->sales?->name ?? $item->formerSales?->name ?? '-' }}</span>
                        @if($item->queue_coordinator_user_id)
                            <span class="text-sm">Ditangani Koordinator {{ $item->coordinator?->name }}</span>
                        @else
                            <form method="POST" action="{{ route('coordinator-leads.transfers.store') }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <input type="hidden" name="queue_id" value="{{ $item->id }}">
                                <input type="hidden" name="lead_ids[]" value="{{ $item->sales_lead_id }}">
                                <label class="text-xs font-bold">Sales tujuan<select name="target_user_id" required class="sales-input mt-1"><option value="">Pilih Sales</option>@foreach($queueTransferTargets->where('branch_id', $item->lead?->branch_id) as $target)<option value="{{ $target->id }}">{{ $target->name }}</option>@endforeach</select></label>
                                <label class="text-xs font-bold">Alasan<select name="reason" required class="sales-input mt-1"><option value="">Pilih alasan</option>@foreach($transferReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach</select></label>
                                <label class="text-xs font-bold">Catatan<input name="notes" class="sales-input mt-1"></label>
                                <x-crm.button type="submit" variant="primary" accent="sales">Alih Lead</x-crm.button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <x-crm.empty-state title="Antrean kosong" description="Tidak ada lead dalam antrean." />
            @endforelse
        </div>
    </x-crm.section>
</div>
@endsection
