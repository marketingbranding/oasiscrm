<?php

namespace App\Http\Controllers\Crm\Traits;

use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

trait FilterableBranch
{
    protected function resolveBranches(): Collection
    {
        if (Auth::user()->canViewAllBranches()) {
            return Branch::where('is_active', true)->get();
        }
        return collect();
    }

    protected function resolveBranchProjects(?int $branchId = null): Collection
    {
        $user = Auth::user();
        $query = LeadMaster::where('is_active', true);

        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->orderBy('project_name')->get();
    }

    protected function resolveSelectedBranchId(?int $selectedBranchId): ?int
    {
        $user = Auth::user();

        if (!$user->canViewAllBranches()) {
            return $user->branch_id;
        }

        if ($selectedBranchId) {
            return $selectedBranchId;
        }

        if ($user->hasRole('pusat') && $user->branch_id) {
            return $user->branch_id;
        }

        return null;
    }

    protected function applyBranchScope(Builder $query, ?int $branchId = null, string $field = 'branch_id'): Builder
    {
        $user = Auth::user();

        if (!$user->canViewAllBranches()) {
            return $query->where($field, $user->branch_id);
        }

        if ($branchId) {
            return $query->where($field, $branchId);
        }

        if ($user->hasRole('pusat') && $user->branch_id) {
            return $query->where($field, $user->branch_id);
        }

        return $query;
    }
}
