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

        if ($user->hasPermission('bridge_fund.view_all')) {
            return true;
        }

        return in_array((int) $danaTalangan->branch_id, app(OrganizationScopeService::class)->branchIds($user, 'bridge_fund'), true)
            && app(WorkspaceAccessService::class)->canViewBranch($user, $danaTalangan->branch_id);
    }
}
