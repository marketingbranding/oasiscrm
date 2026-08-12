<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SalesTeamScopeService
{
    public function for(User $actor): array
    {
        $empty = [
            'supervisors' => collect(),
            'coordinators' => collect(),
            'sales' => collect(),
            'sales_ids_by_coordinator' => collect(),
            'coordinator_ids_by_supervisor' => collect(),
        ];
        $role = $actor->role?->slug;

        if ($role === 'sales') {
            return [...$empty, 'sales' => collect([$actor])];
        }

        if (! in_array($role, ['sales_coordinator', 'supervisor', 'manager', 'branch_manager'], true)) {
            return $empty;
        }

        $users = User::query()
            ->with('role:id,slug')
            ->where('account_status', AccountStatus::Active->value)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
        $bySupervisor = $users->groupBy(fn (User $user) => (int) $user->supervisor_user_id);
        $supervisors = collect();
        $coordinators = collect();

        if ($role === 'sales_coordinator') {
            if ($this->isRole($actor, 'sales_coordinator') && $actor->isAccountActive() && $actor->is_active) {
                $coordinators->put($actor->id, $actor);
            }
        } elseif ($role === 'supervisor') {
            $supervisors->put($actor->id, $actor);
            $coordinators = $this->roleUsers($bySupervisor->get($actor->id, collect()), 'sales_coordinator');
        } else {
            $frontier = collect([$actor->id]);
            $visited = collect([$actor->id => true]);

            while ($frontier->isNotEmpty()) {
                $children = $frontier
                    ->flatMap(fn (int $id) => $bySupervisor->get($id, collect()))
                    ->reject(fn (User $user) => $visited->has($user->id));
                $visited = $visited->union($children->mapWithKeys(fn (User $user) => [$user->id => true]));
                $foundSupervisors = $this->roleUsers($children, 'supervisor');
                $foundCoordinators = $this->roleUsers($children, 'sales_coordinator');
                $supervisors = $supervisors->union($foundSupervisors);
                $coordinators = $coordinators->union($foundCoordinators);
                $frontier = $foundSupervisors->keys();
            }
        }

        $mappings = SalesCoordinatorSales::query()
            ->current()
            ->withValidRoles()
            ->whereIn('coordinator_user_id', $coordinators->keys())
            ->get(['coordinator_user_id', 'sales_user_id']);
        $mappings = $mappings->filter(fn (SalesCoordinatorSales $mapping) => $users->has($mapping->sales_user_id));
        $salesIdsByCoordinator = $mappings
            ->groupBy('coordinator_user_id')
            ->map(fn (Collection $rows) => $rows->pluck('sales_user_id')->map(fn ($id) => (int) $id)->unique()->values());
        $sales = $users->only($mappings->pluck('sales_user_id')->unique()->all())
            ->filter(fn (User $user) => $this->isRole($user, 'sales') && $user->isAccountActive() && $user->is_active)
            ->values();
        $coordinatorIdsBySupervisor = $coordinators
            ->filter(fn (User $coordinator) => $supervisors->has((int) $coordinator->supervisor_user_id) || ($role === 'supervisor' && (int) $coordinator->supervisor_user_id === $actor->id))
            ->groupBy(fn (User $coordinator) => (int) $coordinator->supervisor_user_id)
            ->map(fn (Collection $items) => $items->pluck('id')->map(fn ($id) => (int) $id)->values());

        return [
            'supervisors' => $supervisors->values(),
            'coordinators' => $coordinators->values(),
            'sales' => $sales,
            'sales_ids_by_coordinator' => $salesIdsByCoordinator,
            'coordinator_ids_by_supervisor' => $coordinatorIdsBySupervisor,
        ];
    }

    public function displayedFor(User $actor, array $branchIds, array $projectIds): array
    {
        $team = $this->for($actor);
        $personIds = collect([$team['supervisors'], $team['coordinators'], $team['sales']])
            ->flatten(1)->pluck('id')->unique();
        $today = today()->toDateString();
        $displayedIds = User::query()
            ->whereIn('users.id', $personIds)
            ->where(function (Builder $query) use ($branchIds, $projectIds, $today) {
                $query->whereIn('users.branch_id', $branchIds)
                    ->orWhereHas('assignedProjects', fn (Builder $projects) => $projects
                        ->whereIn('lead_master.id', $projectIds)
                        ->where('lead_master.is_active', true)
                        ->where('project_user.is_active', true)
                        ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', $today))
                        ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', $today)));
            })
            ->pluck('users.id');
        $keep = fn (Collection $users) => $users->whereIn('id', $displayedIds)->values();
        $team['supervisors'] = $keep($team['supervisors']);
        $team['coordinators'] = $keep($team['coordinators']);
        $team['sales'] = $keep($team['sales']);
        $coordinatorIds = $team['coordinators']->pluck('id');
        $salesIds = $team['sales']->pluck('id');
        $team['sales_ids_by_coordinator'] = $team['sales_ids_by_coordinator']
            ->only($coordinatorIds->all())->map(fn (Collection $ids) => $ids->intersect($salesIds)->values());
        $team['coordinator_ids_by_supervisor'] = $team['coordinator_ids_by_supervisor']
            ->only($team['supervisors']->pluck('id')->all())->map(fn (Collection $ids) => $ids->intersect($coordinatorIds)->values());

        return $team;
    }

    public function currentSalesQuery(User $coordinator): Builder
    {
        return User::query()
            ->where('users.account_status', AccountStatus::Active->value)
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
        return $coordinator->hasPrimaryRole('sales_coordinator')
            ? $this->currentSalesQuery($coordinator)->get()
            : collect();
    }

    public function contains(User $coordinator, int|User $sales): bool
    {
        return $coordinator->hasPrimaryRole('sales_coordinator')
            && $this->currentSalesQuery($coordinator)->whereKey($sales instanceof User ? $sales->id : $sales)->exists();
    }

    private function roleUsers(Collection $users, string $role): Collection
    {
        return $users->filter(fn (User $user) => $this->isRole($user, $role))->keyBy('id');
    }

    private function isRole(User $user, string $role): bool
    {
        return $user->role?->slug === $role;
    }
}
