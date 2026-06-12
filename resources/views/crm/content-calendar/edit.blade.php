@extends('layouts.crm')

@section('title', 'Edit Konten - Oasis CRM')

@section('content')
    @dd(get_class($content), $content->id, $content->getRouteKey(), $content->exists)
    <div class="bg-[#b3bd95] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Edit Konten</h1>
    </div>

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Konten
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('content-calendar.update', ['content_calendar' => $content->id]) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Judul Konten</label>
                    <input type="text" name="title" value="{{ old('title', $content->title) }}" required
                        class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('title') border-[#e91d2a] @enderror">
                    @error('title')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                @if(Auth::user()->isSuperadmin() && isset($branches) && $branches->count() > 0)
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <select name="branch_id" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('branch_id') border-[#e91d2a] @enderror">
                        <option value="">— Pilih Cabang —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id', $content->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Platform</label>
                        <select name="platform" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('platform') border-[#e91d2a] @enderror">
                            <option value="Instagram" {{ old('platform', $content->platform) == 'Instagram' ? 'selected' : '' }}>Instagram</option>
                            <option value="Facebook" {{ old('platform', $content->platform) == 'Facebook' ? 'selected' : '' }}>Facebook</option>
                            <option value="TikTok" {{ old('platform', $content->platform) == 'TikTok' ? 'selected' : '' }}>TikTok</option>
                            <option value="YouTube" {{ old('platform', $content->platform) == 'YouTube' ? 'selected' : '' }}>YouTube</option>
                            <option value="Website" {{ old('platform', $content->platform) == 'Website' ? 'selected' : '' }}>Website</option>
                        </select>
                        @error('platform')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal Jadwal</label>
                        <input type="date" name="scheduled_date" value="{{ old('scheduled_date', $content->scheduled_date->format('Y-m-d')) }}" required
                            class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('scheduled_date') border-[#e91d2a] @enderror">
                        @error('scheduled_date')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status</label>
                        <select name="status" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('status') border-[#e91d2a] @enderror">
                            <option value="draft" {{ old('status', $content->status) == 'draft' ? 'selected' : '' }}>Draft</option>
                            <option value="review" {{ old('status', $content->status) == 'review' ? 'selected' : '' }}>Review</option>
                            <option value="approved" {{ old('status', $content->status) == 'approved' ? 'selected' : '' }}>Approved</option>
                            <option value="posted" {{ old('status', $content->status) == 'posted' ? 'selected' : '' }}>Posted</option>
                        </select>
                        @error('status')
                            <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Catatan</label>
                    <textarea name="notes" rows="4" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('notes') border-[#e91d2a] @enderror">{{ old('notes', $content->notes) }}</textarea>
                    @error('notes')
                        <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-between pt-2">
                    <div class="flex items-center gap-3">
                        <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                            Update Konten
                        </button>
                        <a href="{{ route('content-calendar.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                            Batal
                        </a>
                    </div>
                    <form method="POST" action="{{ route('content-calendar.destroy', ['content_calendar' => $content->id]) }}" onsubmit="return confirm('Yakin ingin menghapus konten ini?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bg-white text-[#e91d2a] px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-[#e91d2a] rounded-none hover:bg-red-50">
                            Hapus
                        </button>
                    </form>
                </div>
            </form>
        </div>
    </div>
@endsection
