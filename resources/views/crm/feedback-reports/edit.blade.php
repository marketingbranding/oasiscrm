@extends('layouts.crm')

@section('title', 'Detail Laporan - Oasis CRM')

@section('content')
    @php $user = Auth::user(); @endphp
    <x-crm.page-header color="#c0392b" title="Detail Laporan #{{ $feedbackReport->id }}" />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 border-2 border-black bg-white">
            <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">Informasi Laporan</div>
            <div class="p-4 sm:p-6 space-y-3">
                <div class="grid grid-cols-2 gap-4 text-sm font-['Times_New_Roman']">
                    <div>
                        <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Tipe</span>
                        <p class="mt-0.5">
                            <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $feedbackReport->type === 'bug' ? 'bg-[#d77a7a] text-white' : 'bg-[#e6915d] text-white' }}">
                                {{ $feedbackReport->type === 'bug' ? 'BUG' : 'MASUKAN' }}
                            </span>
                        </p>
                    </div>
                    <div>
                        <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Status</span>
                        <p class="mt-0.5">
                            @php
                                $statusMap = [
                                    'pending' => ['bg-[#f1c40f]', 'PENDING'],
                                    'approved' => ['bg-[#27ae60] text-white', 'DISETUJUI'],
                                    'rejected' => ['bg-[#c0392b] text-white', 'DITOLAK'],
                                    'implemented' => ['bg-[#2980b9] text-white', 'IMPLEMENTASI'],
                                    'fixed' => ['bg-[#7f8c8d] text-white', 'FIXED'],
                                ];
                                [$sClass, $sLabel] = $statusMap[$feedbackReport->status] ?? ['bg-gray-300', strtoupper($feedbackReport->status)];
                            @endphp
                            <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $sClass }}">{{ $sLabel }}</span>
                        </p>
                    </div>
                    <div>
                        <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Cabang</span>
                        <p class="mt-0.5 font-bold">{{ $feedbackReport->branch->name ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Pelapor</span>
                        <p class="mt-0.5">{{ $feedbackReport->creator->name ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Tanggal</span>
                        <p class="mt-0.5">{{ $feedbackReport->created_at->format('d M Y H:i') }}</p>
                    </div>
                    @if($feedbackReport->reviewed_at)
                    <div>
                        <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Direview</span>
                        <p class="mt-0.5">{{ $feedbackReport->reviewed_at->format('d M Y H:i') }} oleh {{ $feedbackReport->reviewer->name ?? '—' }}</p>
                    </div>
                    @endif
                </div>

                <hr class="border-t border-black my-2">

                <div>
                    <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Judul</span>
                    <p class="mt-0.5 text-base font-bold">{{ $feedbackReport->title }}</p>
                </div>

                <div>
                    <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Deskripsi</span>
                    <p class="mt-0.5 text-sm whitespace-pre-wrap">{{ $feedbackReport->description }}</p>
                </div>

                @if($feedbackReport->admin_note)
                <hr class="border-t border-black my-2">
                <div>
                    <span class="text-xs font-[Helvetica] font-bold uppercase text-gray-500">Catatan Admin</span>
                    <p class="mt-0.5 text-sm bg-gray-50 border-2 border-black p-3 whitespace-pre-wrap">{{ $feedbackReport->admin_note }}</p>
                </div>
                @endif

                @if($feedbackReport->user_id === Auth::id() && $feedbackReport->status === 'pending')
                <hr class="border-t border-black my-2">
                <form method="POST" action="{{ route('feedback-reports.update', $feedbackReport->id) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="block text-xs font-[Helvetica] font-bold mb-1">Ubah Judul</label>
                        <input name="title" value="{{ old('title', $feedbackReport->title) }}" maxlength="255"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                    </div>
                    <div>
                        <label class="block text-xs font-[Helvetica] font-bold mb-1">Ubah Deskripsi</label>
                        <textarea name="description" rows="4" maxlength="5000"
                                  class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">{{ old('description', $feedbackReport->description) }}</textarea>
                    </div>
                    <button type="submit" class="bg-black text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan Perubahan
                    </button>
                </form>
                @endif
            </div>
        </div>

        <div class="border-2 border-black bg-white">
            <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">Aksi</div>
            <div class="p-4 space-y-3">
                @if($user->canViewAllBranches())
                <form method="POST" action="{{ route('feedback-reports.approve', $feedbackReport->id) }}" class="space-y-2">
                    @csrf
                    <label class="block text-xs font-[Helvetica] font-bold">Catatan Admin:</label>
                    <textarea name="admin_note" rows="3" maxlength="2000"
                              class="w-full border-2 border-black px-2 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none resize-y"
                              placeholder="Opsional...">{{ $feedbackReport->admin_note }}</textarea>
                    <div class="flex flex-col gap-2">
                        <button type="submit" class="w-full bg-[#27ae60] text-white px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#219a52]"
                                {{ $feedbackReport->status === 'pending' ? '' : 'disabled' }}>
                            ✓ Setujui
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route('feedback-reports.reject', $feedbackReport->id) }}">
                    @csrf
                    <button type="submit" class="w-full bg-[#c0392b] text-white px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#a93226]"
                            {{ $feedbackReport->status === 'pending' ? '' : 'disabled' }}>
                        ✕ Tolak
                    </button>
                </form>

                <form method="POST" action="{{ route('feedback-reports.implement', $feedbackReport->id) }}">
                    @csrf
                    <button type="submit" class="w-full bg-[#2980b9] text-white px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#2471a3]"
                            {{ in_array($feedbackReport->status, ['approved', 'implemented']) ? '' : 'disabled' }}>
                        ◉ Implementasi
                    </button>
                </form>

                <form method="POST" action="{{ route('feedback-reports.fix', $feedbackReport->id) }}">
                    @csrf
                    <button type="submit" class="w-full bg-[#7f8c8d] text-white px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#6c7a7a]"
                            {{ $feedbackReport->type === 'bug' && $feedbackReport->status !== 'fixed' ? '' : 'disabled' }}>
                        ✓ Tandai Fixed
                    </button>
                </form>

                <hr class="border-t border-black my-2">
                @endif

                <a href="{{ route('feedback-reports.index', array_filter(request()->only(['branch_id', 'type', 'status']))) }}"
                   class="block w-full text-center bg-white text-black px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    ← Kembali
                </a>

                @if($user->canViewAllBranches() || $feedbackReport->user_id === Auth::id())
                <form method="POST" action="{{ route('feedback-reports.destroy', $feedbackReport->id) }}"
                      onsubmit="return confirm('Hapus laporan ini?')">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                    <input type="hidden" name="type" value="{{ request('type') }}">
                    <input type="hidden" name="status" value="{{ request('status') }}">
                    <button type="submit" class="w-full bg-white text-[#c0392b] px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-[#c0392b] rounded-none hover:bg-red-50">
                        Hapus Laporan
                    </button>
                </form>
                @endif
            </div>
        </div>
    </div>
@endsection
