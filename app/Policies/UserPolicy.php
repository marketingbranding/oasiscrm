<?php

namespace App\Policies;

use App\Models\User;
use App\Services\ReportingHierarchyService;
use App\Services\UserAdministrationService;

class UserPolicy
{
    public function __construct(private readonly UserAdministrationService $administration) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.view')
            && $this->administration->visibleQuery($actor)->whereKey($target)->exists();
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.create');
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->is($target) || ! $actor->hasPermission('users.update')) {
            return false;
        }

        return $this->administration->visibleQuery($actor)->whereKey($target)->exists()
            && ($actor->isSuperadmin() || (! $target->isSuperadmin()
                && app(ReportingHierarchyService::class)->roleRank($target) <= app(ReportingHierarchyService::class)->roleRank($actor)));
    }
}
