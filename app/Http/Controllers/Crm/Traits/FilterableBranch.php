<?php

namespace App\Http\Controllers\Crm\Traits;

use App\Models\LeadMaster;
use App\Services\WorkspaceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

trait FilterableBranch
{
    protected function resolveBranches(): Collection
    {
        return app(WorkspaceAccessService::class)->accessibleBranches(Auth::user());
    }

    protected function resolveBranchProjects(?int $branchId = null): Collection
    {
        $user = Auth::user();
        $query = LeadMaster::where('is_active', true);

        $branch = app(WorkspaceAccessService::class)->resolveRequestedBranch($user, $branchId);
        if ($branchId && ! $branch) {
            abort(403);
        }
        if ($branch) {
            $query->where('branch_id', $branch->id);
        }

        return $query->orderBy('project_name')->get();
    }

    protected function resolveSelectedBranchId(?int $selectedBranchId): ?int
    {
        $user = Auth::user();

        if ($selectedBranchId) {
            $branch = app(WorkspaceAccessService::class)->resolveRequestedBranch($user, $selectedBranchId);
            abort_unless($branch, 403);

            return $branch->id;
        }

        if ($user->isSuperadmin()) {
            return null;
        }

        return app(WorkspaceAccessService::class)->resolveRequestedBranch($user, null)?->id;
    }

    protected function applyBranchScope(Builder $query, ?int $branchId = null, string $field = 'branch_id'): Builder
    {
        $user = Auth::user();

        if ($branchId) {
            $branch = app(WorkspaceAccessService::class)->resolveRequestedBranch($user, $branchId);
            abort_unless($branch, 403);

            return $query->where($field, $branch->id);
        }

        if ($user->isSuperadmin()) {
            return $query;
        }

        $branch = app(WorkspaceAccessService::class)->resolveRequestedBranch($user, null);

        return $branch ? $query->where($field, $branch->id) : $query->whereRaw('1 = 0');
    }
}
