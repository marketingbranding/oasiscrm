<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly ReportingHierarchyService $hierarchy,
    ) {}

    public function visibleQuery(User $actor): Builder
    {
        if ($actor->isSuperadmin() || $actor->hasPrimaryRole('pusat')) {
            return User::query();
        }

        $branchIds = $this->scope->branchIds($actor);

        return User::query()->where(function (Builder $query) use ($actor, $branchIds) {
            $query->whereKey($actor->id)
                ->orWhereIn('branch_id', $branchIds)
                ->orWhereHas('branches', fn (Builder $branches) => $branches->whereIn('branches.id', $branchIds));
        });
    }

    public function assertCanManage(User $actor, User $target, string $permission): void
    {
        abort_unless($actor->hasPermission($permission), 403);
        abort_if($actor->is($target), 403, 'Anda tidak dapat mengubah peran, status, atau penugasan akun sendiri.');
        abort_unless($this->visibleQuery($actor)->whereKey($target)->exists(), 403);
        abort_if(! $actor->isSuperadmin() && $target->isSuperadmin(), 403);
        abort_if($this->hierarchy->roleRank($target) > $this->hierarchy->roleRank($actor), 403);
    }

    public function assertCanAssignRole(User $actor, Role $role, ?User $target = null): void
    {
        $role->loadMissing('permissions');
        abort_unless($actor->hasPermission('users.assign_roles') || ($target === null && $actor->hasPermission('users.create')), 403);
        abort_if(! $actor->isSuperadmin() && $role->is_superadmin, 403);
        abort_if(! $actor->isSuperadmin() && $role->permissions->contains(fn ($permission) => in_array($permission->slug, ['roles.manage', 'permissions.manage'], true)), 403);
        abort_if($this->hierarchy->roleRank($role->slug) > $this->hierarchy->roleRank($actor), 403);

        if ($target) {
            $this->assertCanManage($actor, $target, 'users.update');
        }
    }

    public function assertAssignmentsInScope(User $actor, array $branchIds, array $projectIds): void
    {
        if ($actor->isSuperadmin() || $actor->hasPrimaryRole('pusat')) {
            return;
        }

        $allowedBranches = $this->scope->branchIds($actor);
        $allowedProjects = $this->scope->projectIds($actor);
        abort_if(array_diff($branchIds, $allowedBranches) !== [] || array_diff($projectIds, $allowedProjects) !== [], 403);
    }

    public function assertNotLastActiveSuperadmin(User $target): void
    {
        if (! $target->isSuperadmin() || $target->account_status !== AccountStatus::Active) {
            return;
        }

        $active = User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('role', fn (Builder $query) => $query->where('is_superadmin', true))
            ->count();

        if ($active <= 1) {
            throw ValidationException::withMessages(['status' => 'Superadmin aktif terakhir tidak dapat dinonaktifkan atau ditangguhkan.']);
        }
    }

    public function availableRoles(User $actor)
    {
        return Role::query()->with('permissions')->where('is_active', true)
            ->when(! $actor->isSuperadmin(), fn (Builder $query) => $query->where('is_superadmin', false))
            ->get()
            ->filter(fn (Role $role) => $this->hierarchy->roleRank($role->slug) <= $this->hierarchy->roleRank($actor)
                && ($actor->isSuperadmin() || ! $role->permissions->contains(fn ($permission) => in_array($permission->slug, ['roles.manage', 'permissions.manage'], true))))
            ->values();
    }
}
