<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CoordinatorLeadTeamService
{
    public function __construct(private readonly SalesTeamScopeService $salesTeamScope) {}

    public function isCoordinator(User $user): bool
    {
        return $user->hasPrimaryRole('sales_coordinator');
    }

    public function currentSalesQuery(User $coordinator): Builder
    {
        return $this->salesTeamScope->currentSalesQuery($coordinator);
    }

    public function currentSales(User $coordinator): Collection
    {
        return $this->salesTeamScope->currentSales($coordinator);
    }

    public function contains(User $coordinator, int|User $sales): bool
    {
        return $this->salesTeamScope->contains($coordinator, $sales);
    }
}
