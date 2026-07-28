@php($selectedBranchIds = array_map('intval', old('branch_ids', isset($user) ? $user->branches->pluck('id')->all() : [])))
<div>
    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang Tambahan</label>
    <div class="border-2 border-black p-3 grid gap-2 sm:grid-cols-2">
        @foreach($branches as $branch)
            <label class="flex items-center gap-2 text-sm font-['Times_New_Roman']"><input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked(in_array((int) $branch->id, $selectedBranchIds, true))> {{ $branch->name }} ({{ $branch->code }})</label>
        @endforeach
    </div>
    @error('branch_ids')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
    @error('branch_ids.*')<p class="text-[#e91d2a] text-xs mt-1 font-bold">{{ $message }}</p>@enderror
</div>
