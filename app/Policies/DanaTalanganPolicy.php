<?php

namespace App\Policies;

use App\Models\DanaTalangan;
use App\Models\User;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;

class DanaTalanganPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('bridge_fund.view') && $user->hasScopedPermission('bridge_fund');
    }

    public function view(User $user, DanaTalangan $danaTalangan): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->hasPermission('bridge_fund.view_all')
            && ! app(WorkspaceAccessService::class)->isBranchScopedOperator($user)) {
            return true;
        }

        return app(OrganizationScopeService::class)->allowsProjectRecord(
            $user,
            'bridge_fund',
            'view',
            (int) $danaTalangan->branch_id,
            $danaTalangan->project_id ? (int) $danaTalangan->project_id : null,
        );
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('bridge_fund.manage')
            && $user->hasScopedPermission('bridge_fund', 'manage')
            && app(WorkspaceAccessService::class)->editableBranches($user, 'bridge_fund')->isNotEmpty();
    }

    public function update(User $user, DanaTalangan $danaTalangan): bool
    {
        return $user->hasPermission('bridge_fund.manage')
            && app(OrganizationScopeService::class)->allowsProjectRecord(
                $user,
                'bridge_fund',
                'manage',
                (int) $danaTalangan->branch_id,
                $danaTalangan->project_id ? (int) $danaTalangan->project_id : null,
            )
            && app(WorkspaceAccessService::class)->canEditBranch($user, (int) $danaTalangan->branch_id);
    }

    public function delete(User $user, DanaTalangan $danaTalangan): bool
    {
        return $this->update($user, $danaTalangan);
    }
}
