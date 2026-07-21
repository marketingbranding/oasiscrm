<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

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
