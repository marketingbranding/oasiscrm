@php($selectedBranchIds = array_map('intval', old('branch_ids', isset($user) ? $user->branches->pluck('id')->all() : [])))
<section class="border-t-2 border-black pt-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><h2 class="font-[Helvetica] font-bold text-xs uppercase">Cabang yang dapat diakses</h2><p class="mt-1 text-xs text-gray-600">Cabang utama tetap dipilih di atas. Tambahkan cabang lain bila diperlukan.</p></div>
        <label class="w-full sm:w-56"><span class="sr-only">Cari cabang</span><input type="search" x-model="branchSearch" placeholder="Cari cabang atau kode" class="w-full border-2 border-black px-3 py-2 text-sm"></label>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        @foreach($branches as $branch)
            <label data-search="{{ strtolower($branch->name.' '.$branch->code) }}" x-show="matchesOption($el.dataset.search, branchSearch)" class="crm-choice-chip"><input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" x-model="selectedBranchIds" @change="syncProjectSelection()" @checked(in_array((int) $branch->id, $selectedBranchIds, true))><span>{{ $branch->name }} <small>({{ $branch->code }})</small></span></label>
        @endforeach
    </div>
    <p class="mt-2 text-xs text-gray-600"><span x-text="selectedBranchIds.length"></span> cabang dipilih</p>
    @error('branch_ids')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
    @error('branch_ids.*')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
</section>
