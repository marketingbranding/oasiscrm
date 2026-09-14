<?php

namespace App\Policies;

use App\Models\DanaTalangan;
use App\Models\User;
use App\Services\OrganizationScopeService;

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

        return app(OrganizationScopeService::class)->allowsProjectRecord(
            $user,
            'bridge_fund',
            'view',
            (int) $danaTalangan->branch_id,
            $danaTalangan->project_id ? (int) $danaTalangan->project_id : null,
        );
    }
}
