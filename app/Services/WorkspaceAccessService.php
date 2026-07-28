<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceAccessService
{
    public function accessibleBranches(User $user): Collection
    {
        if ($this->hasGlobalCompatibilityAccess($user)) {
            return Branch::where('is_active', true)->forDropdown()->get();
        }

        $branches = $user->branches()
            ->where('branches.is_active', true)
            ->wherePivot('can_view', true)
            ->forDropdown()
            ->get();

        $hasPrimaryMembership = $user->branch_id
            && $user->branches()->whereKey($user->branch_id)->exists();
        if ($user->branch_id && ! $hasPrimaryMembership && ! $branches->contains('id', $user->branch_id)) {
            $primary = Branch::whereKey($user->branch_id)->where('is_active', true)->first();
            if ($primary) {
                $branches->prepend($primary);
            }
        }

        return $branches->unique('id')->values();
    }

    public function accessibleBranchIds(User $user): array
    {
        return $this->accessibleBranches($user)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function accessibleProjectsQuery(User $user, bool $activeOnly = true): Builder
    {
        $query = LeadMaster::query();

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($user->isSales()) {
            $today = today()->toDateString();

            return $query
                ->whereIn('branch_id', $this->accessibleBranchIds($user))
                ->whereIn('id', DB::table('project_user')
                    ->select('project_id')
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->where(fn ($window) => $window
                        ->whereNull('assignment_start_date')
                        ->orWhereDate('assignment_start_date', '<=', $today))
                    ->where(fn ($window) => $window
                        ->whereNull('assignment_end_date')
                        ->orWhereDate('assignment_end_date', '>=', $today)));
        }

        return $query->whereIn('branch_id', $this->accessibleBranchIds($user));
    }

    public function accessibleProjects(User $user, bool $activeOnly = true): Collection
    {
        return $this->accessibleProjectsQuery($user, $activeOnly)
            ->with('branch')
            ->orderBy('project_name')
            ->get();
    }

    public function accessibleProjectIds(User $user, bool $activeOnly = true): array
    {
        return $this->accessibleProjectsQuery($user, $activeOnly)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canAccessProject(User $user, int|LeadMaster $project): bool
    {
        $projectId = $project instanceof LeadMaster ? $project->id : (int) $project;

        return $this->accessibleProjectsQuery($user)->whereKey($projectId)->exists();
    }

    public function resolveRequestedProject(User $user, mixed $requestedProjectId): ?LeadMaster
    {
        if (filled($requestedProjectId)) {
            return $this->accessibleProjectsQuery($user)->whereKey((int) $requestedProjectId)->first();
        }

        if ($user->isSales()) {
            return $user->primaryAssignedProject()
                ->where('lead_master.is_active', true)
                ->wherePivot('is_active', true)
                ->where(fn (Builder $query) => $query
                    ->whereNull('project_user.assignment_start_date')
                    ->orWhereDate('project_user.assignment_start_date', '<=', today()->toDateString()))
                ->where(fn (Builder $query) => $query
                    ->whereNull('project_user.assignment_end_date')
                    ->orWhereDate('project_user.assignment_end_date', '>=', today()->toDateString()))
                ->first() ?? $this->accessibleProjectsQuery($user)->orderBy('project_name')->first();
        }

        return $this->accessibleProjectsQuery($user)->orderBy('project_name')->first();
    }

    public function canAccessBranch(User $user, int|Branch $branch): bool
    {
        return $this->canViewBranch($user, $branch);
    }

    public function canViewBranch(User $user, int|Branch $branch): bool
    {
        return $this->hasPermission($user, $branch, 'can_view');
    }

    public function canEditBranch(User $user, int|Branch $branch): bool
    {
        return $this->hasPermission($user, $branch, 'can_edit');
    }

    public function canSyncBranch(User $user, int|Branch $branch): bool
    {
        return $this->hasPermission($user, $branch, 'can_sync');
    }

    public function canManageBranchMembers(User $user, int|Branch $branch): bool
    {
        return $this->hasPermission($user, $branch, 'can_manage_members', false);
    }

    public function primaryBranch(User $user): ?Branch
    {
        return $user->branch_id ? Branch::find($user->branch_id) : null;
    }

    public function resolveRequestedBranch(User $user, mixed $requestedBranchId): ?Branch
    {
        if (filled($requestedBranchId)) {
            $branch = Branch::whereKey((int) $requestedBranchId)->where('is_active', true)->first();

            return $branch && $this->canViewBranch($user, $branch) ? $branch : null;
        }

        $primary = $this->primaryBranch($user);
        if ($primary?->is_active && $this->canViewBranch($user, $primary)) {
            return $primary;
        }

        return $this->accessibleBranches($user)->first();
    }

    private function hasPermission(User $user, int|Branch $branch, string $permission, bool $primaryFallback = true): bool
    {
        $branchId = $branch instanceof Branch ? $branch->id : (int) $branch;
        $active = $branch instanceof Branch ? $branch->is_active : Branch::whereKey($branchId)->where('is_active', true)->exists();
        if (! $active) {
            return false;
        }

        if ($this->hasGlobalCompatibilityAccess($user)) {
            return true;
        }

        $membership = $user->branches()->whereKey($branchId)->first()?->pivot;
        if ($membership) {
            return (bool) $membership->{$permission};
        }

        return $primaryFallback && (int) $user->branch_id === $branchId;
    }

    private function hasGlobalCompatibilityAccess(User $user): bool
    {
        // Transitional compatibility: pusat keeps the existing global behavior while new branch-scoped code uses memberships.
        return $user->canViewAllBranches();
    }
}
