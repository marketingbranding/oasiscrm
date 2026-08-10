<?php

namespace App\Services;

use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CoordinatorLeadTeamService
{
    public function isCoordinator(User $user): bool
    {
        return $user->hasPrimaryRole('sales_coordinator');
    }

    public function currentSalesQuery(User $coordinator): Builder
    {
        return User::query()
            ->where('users.is_active', true)
            ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
            ->whereIn('users.id', SalesCoordinatorSales::query()
                ->select('sales_user_id')
                ->where('coordinator_user_id', $coordinator->id)
                ->current()
                ->withValidRoles());
    }

    public function currentSales(User $coordinator): Collection
    {
        if (! $this->isCoordinator($coordinator)) {
            return collect();
        }

        return $this->currentSalesQuery($coordinator)->get();
    }

    public function contains(User $coordinator, int|User $sales): bool
    {
        return $this->isCoordinator($coordinator)
            && $this->currentSalesQuery($coordinator)->whereKey($sales instanceof User ? $sales->id : $sales)->exists();
    }
}
