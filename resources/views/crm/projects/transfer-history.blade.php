@extends('layouts.crm')

@section('title', 'Riwayat Pindah Proyek - Oasis CRM')

@section('content')
    <x-crm.page-header color="#5d8e8e" title="Riwayat Pindah Proyek" />
    <div class="border-2 border-black bg-white overflow-auto">
        <table class="w-full text-sm">
            <thead class="bg-black text-white font-[Helvetica] text-xs uppercase">
                <tr>
                    <th class="px-3 py-2 text-left">Waktu</th><th class="px-3 py-2 text-left">Asal</th><th class="px-3 py-2 text-left">Tujuan</th><th class="px-3 py-2 text-left">Proyek</th><th class="px-3 py-2 text-left">Jumlah</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($batches as $batch)
                    <tr>
                        <td class="px-3 py-2">{{ $batch->executed_at?->format('d-m-Y H:i:s') }}</td>
                        <td class="px-3 py-2">{{ $batch->sourceBranch?->name }}</td>
                        <td class="px-3 py-2">{{ $batch->targetBranch?->name }}</td>
                        <td class="px-3 py-2">{{ count($batch->project_ids ?? []) }}</td>
                        <td class="px-3 py-2">{{ $batch->items()->count() }}</td>
                        <td class="px-3 py-2">{{ $batch->status }}</td>
                        <td class="px-3 py-2">
                            @if(in_array($batch->status, ['committed', 'completed', 'cache_failed'], true))
                                <form method="POST" action="{{ route('projects.transfer.rollback', $batch) }}" class="flex flex-wrap gap-2 items-center" x-data="{ confirmation: '', error: '' }" @submit.prevent="fetch($el.action, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}, body:JSON.stringify({confirmation})}).then(response => response.ok ? window.location.reload() : response.json().then(data => { error = data.message || 'Rollback ditolak.' }))" >
                                    @csrf
                                    <label class="sr-only" for="rollback-confirmation-{{ $batch->id }}">Ketik nama cabang tujuan</label>
                                    <input id="rollback-confirmation-{{ $batch->id }}" type="text" x-model="confirmation" placeholder="{{ $batch->targetBranch?->name }}" aria-label="Konfirmasi nama cabang" class="border-2 border-black px-2 py-1 w-36 rounded-none">
                                    <button class="border-2 border-[#e91d2a] text-[#e91d2a] px-2 py-1 font-bold">Rollback</button>
                                    <span x-show="error" x-text="error" role="alert" class="basis-full text-[#e91d2a] text-xs"></span>
                                </form>
                            @elseif($batch->status === 'rolled_back')
                                <span class="text-xs">Selesai dibatalkan</span>
                            @endif
                            @if(collect([$batch->impact_summary['cache_rebuild'] ?? [], $batch->impact_summary['rollback_cache_rebuild'] ?? []])->flatten(1)->contains(fn ($result): bool => is_array($result) && ($result['ok'] ?? false) === false))
                                <form method="POST" action="{{ route('projects.transfer.rebuild', $batch) }}" class="inline" x-data="{ error: '' }" @submit.prevent="fetch($el.action, {method:'POST', headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}}).then(response => response.ok ? window.location.reload() : response.json().then(data => { error = data.message || 'Cache gagal dibangun ulang.' }))">
                                    @csrf
                                    <button type="submit" class="border-2 border-black px-2 py-1 font-bold">Bangun ulang cache</button>
                                    <span x-show="error" x-text="error" role="alert" class="text-[#e91d2a] text-xs"></span>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center">Belum ada batch.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $batches->links() }}</div>
@endsection
