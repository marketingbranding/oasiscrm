@extends('layouts.crm')

@section('title', 'Status Migrasi Cabang')
@section('page-title', 'Status Migrasi Cabang')
@section('content')
    <div class="space-y-6">
        @if ($errors->any())
            <div class="border-2 border-red-700 bg-red-50 p-3 text-red-800" role="alert">
                <p class="font-semibold">Status migrasi belum disimpan.</p>
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <div>
            <h1 class="text-2xl font-semibold">Status Migrasi Cabang</h1>
            <p class="mt-1 text-sm text-slate-600">Perubahan status wajib berurutan dan memakai alasan.</p>
        </div>

        <div class="overflow-x-auto rounded border border-slate-200 bg-white">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-100">
                    <tr><th class="px-4 py-3">Cabang</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Paritas terakhir</th><th class="px-4 py-3">Diubah</th><th class="px-4 py-3">Oleh</th><th class="px-4 py-3">Alasan</th><th class="px-4 py-3">Aksi</th></tr>
                </thead>
                <tbody>
                    @foreach ($branches as $branch)
                        @php($state = $branch->migrationState?->state ?? 'sheet')
                        <tr class="border-t border-slate-200" x-data="{ open: {{ $errors->any() && (string) old('branch_id') === (string) $branch->id ? 'true' : 'false' }}, confirm: false, newState: '{{ $state }}' }">
                            <td class="px-4 py-3">{{ $branch->name }}</td>
                            <td class="px-4 py-3 font-medium">
                                {{ $state }}
                                @if (data_get($migrationOverrides ?? [], $branch->id, false))
                                    <x-crm.status-badge variant="warning">Override</x-crm.status-badge>
                                @endif
                            </td>
                            @php($parity = $paritySnapshots->get($branch->id))
                            <td class="px-4 py-3"><span>{{ $parity?->status ?? 'Belum ada' }}</span><br><span class="text-xs text-slate-500">{{ $parity?->checked_at?->format('d M Y H:i') ?? '—' }}</span><br><a class="underline" href="{{ route('admin.branch-parity.index', ['branch' => $branch->id]) }}">Laporan paritas</a></td>
                            <td class="px-4 py-3">{{ $branch->migrationState?->changed_at?->format('d M Y H:i') ?? 'Belum diubah' }}</td>
                            <td class="px-4 py-3">{{ $branch->migrationState?->changedBy?->name ?? '—' }}</td>
                            <td class="max-w-xs px-4 py-3">{{ $branch->migrationState?->notes ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <button type="button" class="rounded border px-3 py-1" @click="open = true">Ubah status</button>
                                <div x-show="open" x-cloak @keydown.escape.window="open = false" class="fixed inset-0 z-40 grid place-items-center bg-black/40 p-4">
                                    <div role="dialog" aria-modal="true" aria-labelledby="migration-state-{{ $branch->id }}" class="w-full max-w-lg rounded bg-white p-5 shadow-xl">
                                        <h2 id="migration-state-{{ $branch->id }}" class="text-lg font-semibold">Ubah status {{ $branch->name }}</h2>
                                        <form method="POST" action="{{ route('admin.branch-migration-states.update', $branch) }}" class="mt-4 space-y-4">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="expected_state" value="{{ $state }}">
                                            <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                                            <label class="block">Status baru
                                                <select name="state" x-model="newState" class="mt-1 block w-full rounded border p-2" required>
                                                    @foreach (['sheet', 'migrasi', 'paralel', 'oasis_penuh'] as $option)
                                                        <option value="{{ $option }}">{{ $option }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="block">Alasan wajib
                                                <textarea name="reason" required maxlength="1000" class="mt-1 block w-full rounded border p-2">{{ old('reason') }}</textarea>
                                            </label>
                                            @if (auth()->user()?->isSuperadmin() && app(\App\Services\SystemAdministrationAuthorizationService::class)->canManage(auth()->user(), \App\Services\SystemAdministrationAuthorizationService::SETTINGS) && $state === 'paralel')
                                                <div x-show="newState === 'oasis_penuh'" x-cloak>
                                                <label class="flex items-start gap-2">
                                                    <input type="hidden" name="override" value="0">
                                                    <input type="checkbox" name="override" value="1" class="mt-1">
                                                    <span>Lewati gerbang paritas</span>
                                                </label>
                                                </div>
                                            @endif
                                            @error('reason') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                                            @error('state') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                                            <div class="flex justify-end gap-2"><button type="button" class="rounded border px-3 py-2" @click="open = false">Batal</button><button type="button" class="rounded bg-slate-900 px-3 py-2 text-white" @click="confirm = true">Tinjau perubahan</button></div>
                                            <div x-show="confirm" x-cloak class="border-t pt-4" role="alert">
                                                <p class="text-sm">Konfirmasi perubahan status. Sistem menolak lompatan tahap dan status lama yang sudah berubah.</p>
                                                <div class="mt-3 flex justify-end gap-2"><button type="button" class="rounded border px-3 py-2" @click="confirm = false">Kembali</button><button type="submit" class="rounded bg-slate-900 px-3 py-2 text-white">Konfirmasi dan simpan</button></div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $branches->links() }}
    </div>
@endsection
