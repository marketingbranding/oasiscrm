<?php

namespace App\Policies;

use App\Models\Promo;
use App\Models\User;
use App\Services\PromoAccessService;

class PromoPolicy
{
    public function __construct(private PromoAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowedBranches($user)->isNotEmpty()
            || ($user->isSuperadmin() && $user->hasScopedPermission('sales_pocketbook', 'manage'));
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Promo $promo): bool
    {
        return $this->access->canManageBranch($user, $promo->branch);
    }

    public function toggle(User $user, Promo $promo): bool
    {
        return $this->update($user, $promo);
    }
}
