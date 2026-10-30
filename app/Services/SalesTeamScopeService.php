<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\OrganizationAssignment;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class SalesTeamScopeService
{
    public function __construct(
        private readonly OrganizationGraphService $graph,
        private readonly OrganizationGraphModeService $mode,
        private readonly OrganizationGraphShadowService $shadow,
    ) {}

    public function for(User $actor, bool $includeHistorical = false, ?int $branchId = null): array
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

        $legacy = $this->legacyFor($actor, $empty, $includeHistorical, $role);
        $effectiveMode = $this->mode->modeFor($actor, $branchId);
        if ($effectiveMode === 'graph') {
            try {
                return $this->filterTeamToBranch($this->canonicalFor($actor, $empty, $includeHistorical), $branchId);
            } catch (Throwable) {
                return $legacy;
            }
        }

        if ($effectiveMode === 'shadow') {
            try {
                $canonical = $this->canonicalFor($actor, $empty, $includeHistorical);
            } catch (Throwable) {
                $this->shadow->recordFailure('sales_team_scope');

                return $legacy;
            }
            $this->shadow->record('sales_team_scope', $this->normalizedTeam($legacy) === $this->normalizedTeam($canonical));
        }

        return $legacy;
    }

    private function legacyFor(User $actor, array $empty, bool $includeHistorical, ?string $role): array
    {
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
            ->when($includeHistorical, fn (Builder $query) => $query->currentOrHistorical(), fn (Builder $query) => $query->current())
            ->withValidRoles()
            ->whereIn('coordinator_user_id', $coordinators->keys())
            ->get(['coordinator_user_id', 'sales_user_id']);
        if ($includeHistorical) {
            $currentMappings = SalesCoordinatorSales::query()
                ->current()
                ->withValidRoles()
                ->whereIn('coordinator_user_id', $coordinators->keys())
                ->get(['coordinator_user_id', 'sales_user_id'])
                ->mapWithKeys(fn (SalesCoordinatorSales $mapping) => ["{$mapping->coordinator_user_id}:{$mapping->sales_user_id}" => true]);
            $currentSalesIds = $currentMappings->keys()->map(fn (string $key) => (int) explode(':', $key, 2)[1])->unique();
            $mappings = $mappings->filter(fn (SalesCoordinatorSales $mapping) => $currentMappings->has("{$mapping->coordinator_user_id}:{$mapping->sales_user_id}")
                || ! $currentSalesIds->contains((int) $mapping->sales_user_id));
        }
        $mappings = $mappings->filter(fn (SalesCoordinatorSales $mapping) => $users->has($mapping->sales_user_id));
        $mappings = $mappings
            ->groupBy(fn (SalesCoordinatorSales $mapping): string => $mapping->coordinator_user_id.':'.$mapping->sales_user_id)
            ->filter(fn (Collection $rows): bool => $rows->count() === 1)
            ->flatten(1);
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

    public function displayedFor(User $actor, array $branchIds, array $projectIds, bool $includeHistorical = false, ?int $branchContext = null): array
    {
        $team = $this->for($actor, $includeHistorical, $branchContext);
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

    public function forWorkspace(User $actor, array $branchIds, array $projectIds, bool $includeHistorical = false, ?int $branchContext = null): array
    {
        $team = $this->displayedFor($actor, $branchIds, $projectIds, $includeHistorical, $branchContext);
        $branchIds = collect($branchIds)->map(fn ($id) => (int) $id)->values()->all();
        $projectIds = collect($projectIds)->map(fn ($id) => (int) $id)->values()->all();
        $hierarchyIds = collect([$team['supervisors'], $team['coordinators']])->flatten(1)->pluck('id')->unique();
        $salesIds = $team['sales']->pluck('id')->unique();
        $allowedHierarchyIds = User::query()
            ->whereIn('id', $hierarchyIds)
            ->where(function (Builder $query) use ($branchIds, $projectIds, $includeHistorical): void {
                $this->workspacePeople($query, $branchIds, $projectIds, $includeHistorical);
            })
            ->pluck('id');
        $allowedSalesIds = User::query()
            ->whereIn('id', $salesIds)
            ->where(function (Builder $query) use ($branchIds, $projectIds, $includeHistorical): void {
                $this->workspacePeople($query, $branchIds, $projectIds, $includeHistorical);
            })
            ->pluck('id');
        $keepHierarchy = fn (Collection $users): Collection => $users->whereIn('id', $allowedHierarchyIds)->values();
        $team['supervisors'] = $keepHierarchy($team['supervisors']);
        $team['coordinators'] = $keepHierarchy($team['coordinators']);
        $team['sales'] = $team['sales']->whereIn('id', $allowedSalesIds)->values();
        $coordinatorIds = $team['coordinators']->pluck('id');
        $filteredSalesIds = $team['sales']->pluck('id');
        $team['sales_ids_by_coordinator'] = $team['sales_ids_by_coordinator']
            ->only($coordinatorIds->all())
            ->map(fn (Collection $ids): Collection => $ids->intersect($filteredSalesIds)->values());
        $team['coordinator_ids_by_supervisor'] = $team['coordinator_ids_by_supervisor']
            ->only($team['supervisors']->pluck('id')->all())
            ->map(fn (Collection $ids): Collection => $ids->intersect($coordinatorIds)->values());

        return $team;
    }

    public function currentSalesQuery(User $coordinator, ?int $branchId = null): Builder
    {
        if (! $coordinator->hasPrimaryRole('sales_coordinator') || ! $coordinator->isAccountActive() || ! $coordinator->is_active) {
            return User::query()->whereKey(0);
        }

        if ($this->mode->isGraph($coordinator, $branchId)) {
            try {
                return User::query()
                    ->where('users.account_status', AccountStatus::Active->value)
                    ->where('users.is_active', true)
                    ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
                    ->when($branchId !== null, fn (Builder $query) => $query->where('users.branch_id', $branchId))
                    ->whereIn('users.id', $this->graph->salesTeamMemberIds($coordinator, strict: true));
            } catch (Throwable) {
            }
        }

        $ambiguousSalesIds = SalesCoordinatorSales::query()
            ->current()
            ->withValidRoles()
            ->where('coordinator_user_id', $coordinator->id)
            ->select('sales_user_id')
            ->groupBy('sales_user_id')
            ->havingRaw('COUNT(*) > 1');

        return User::query()
            ->where('users.account_status', AccountStatus::Active->value)
            ->where('users.is_active', true)
            ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
            ->whereIn('users.id', SalesCoordinatorSales::query()
                ->select('sales_user_id')
                ->where('coordinator_user_id', $coordinator->id)
                ->current()
                ->withValidRoles())
            ->whereNotIn('users.id', $ambiguousSalesIds);
    }

    public function currentSales(User $coordinator, ?int $branchId = null): Collection
    {
        return $coordinator->hasPrimaryRole('sales_coordinator')
            ? $this->currentSalesQuery($coordinator, $branchId)->get()
            : collect();
    }

    public function contains(User $coordinator, int|User $sales, ?int $branchId = null): bool
    {
        return $coordinator->hasPrimaryRole('sales_coordinator')
            && $this->currentSalesQuery($coordinator, $branchId)->whereKey($sales instanceof User ? $sales->id : $sales)->exists();
    }

    private function roleUsers(Collection $users, string $role): Collection
    {
        return $users->filter(fn (User $user) => $this->isRole($user, $role))->keyBy('id');
    }

    private function isRole(User $user, string $role): bool
    {
        return $user->role?->slug === $role;
    }

    /** @return array<string, array<int>> */
    private function normalizedTeam(array $team): array
    {
        return [
            'supervisors' => $team['supervisors']->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'coordinators' => $team['coordinators']->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'sales' => $team['sales']->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
        ];
    }

    private function filterTeamToBranch(array $team, ?int $branchId): array
    {
        if ($branchId === null) {
            return $team;
        }

        $keep = fn (Collection $users): Collection => $users->filter(fn (User $user): bool => (int) $user->branch_id === $branchId)->values();
        $team['supervisors'] = $keep($team['supervisors']);
        $team['coordinators'] = $keep($team['coordinators']);
        $team['sales'] = $keep($team['sales']);
        $coordinatorIds = $team['coordinators']->pluck('id');
        $salesIds = $team['sales']->pluck('id');
        $team['sales_ids_by_coordinator'] = $team['sales_ids_by_coordinator']
            ->only($coordinatorIds->all())
            ->map(fn (Collection $ids): Collection => $ids->intersect($salesIds)->values());
        $team['coordinator_ids_by_supervisor'] = $team['coordinator_ids_by_supervisor']
            ->only($team['supervisors']->pluck('id')->all())
            ->map(fn (Collection $ids): Collection => $ids->intersect($coordinatorIds)->values());

        return $team;
    }

    private function workspacePeople(Builder $query, array $branchIds, array $projectIds, bool $includeHistorical): void
    {
        $query->whereIn('branch_id', $branchIds)
            ->where(function (Builder $scope) use ($projectIds, $includeHistorical): void {
                $scope->whereDoesntHave('assignedProjects', fn (Builder $projects) => $this->workspaceProjectAssignment($projects, $includeHistorical))
                    ->orWhereHas('assignedProjects', fn (Builder $projects) => $this->workspaceProjectAssignment($projects, $includeHistorical)->whereIn('lead_master.id', $projectIds));
            });
    }

    private function workspaceProjectAssignment(Builder $query, bool $includeHistorical): Builder
    {
        return $query
            ->where('lead_master.is_active', true)
            ->where('project_user.is_active', true)
            ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', today()->toDateString()))
            ->when(! $includeHistorical, fn (Builder $dates) => $dates->where(fn (Builder $endDate) => $endDate->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', today()->toDateString())));
    }

    private function canonicalFor(User $actor, array $empty, bool $includeHistorical = false): array
    {
        $role = $actor->role?->slug;
        if (! in_array($role, ['sales_coordinator', 'supervisor', 'manager', 'branch_manager'], true)) {
            return $empty;
        }

        $people = collect([$actor, ...$this->graph->descendants($actor, strict: true)])->keyBy('id');
        $supervisors = $role === 'supervisor'
            ? collect([$actor])
            : $people->filter(fn (User $user) => $this->isRole($user, 'supervisor'))->values();
        $coordinators = $role === 'sales_coordinator'
            ? collect([$actor])
            : $people->filter(fn (User $user) => $this->isRole($user, 'sales_coordinator'))->values();
        $sales = $people->filter(fn (User $user) => $this->isRole($user, 'sales')
            && $user->isAccountActive()
            && $user->is_active)->values();
        $reportingAssignments = OrganizationAssignment::query()->current()
            ->whereIn('user_id', $people->keys())
            ->get(['user_id', 'parent_user_id'])
            ->keyBy('user_id');
        $salesTeamAssignments = $coordinators
            ->flatMap(fn (User $coordinator): Collection => $this->graph->currentSalesTeamAssignments($coordinator, strict: true))
            ->map(fn (OrganizationAssignment $assignment): array => [
                'user_id' => (int) $assignment->user_id,
                'parent_user_id' => (int) $assignment->parent_user_id,
            ])
            ->values();
        $mappedSales = User::query()->with('role:id,slug')
            ->whereIn('id', $salesTeamAssignments->pluck('user_id')->unique())
            ->where('account_status', AccountStatus::Active->value)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $this->isRole($user, 'sales'));
        $sales = $sales->merge($mappedSales)->unique('id')->values();
        $salesIdsByCoordinator = $salesTeamAssignments
            ->groupBy('parent_user_id')
            ->map(fn (Collection $items) => $items->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values());
        if ($includeHistorical) {
            $historicalMappings = collect(OrganizationAssignment::query()
                ->salesTeamMemberOf()
                ->whereDate('started_at', '<=', today())
                ->whereIn('parent_user_id', $coordinators->pluck('id'))
                ->get(['parent_user_id', 'user_id'])
                ->map(fn (OrganizationAssignment $assignment): array => [
                    'parent_user_id' => (int) $assignment->parent_user_id,
                    'user_id' => (int) $assignment->user_id,
                ])->all());
            $historicalMappings = $historicalMappings->merge(
                SalesCoordinatorSales::query()
                    ->currentOrHistorical()
                    ->withValidRoles()
                    ->whereIn('coordinator_user_id', $coordinators->pluck('id'))
                    ->get(['coordinator_user_id', 'sales_user_id'])
                    ->map(fn (SalesCoordinatorSales $mapping): array => [
                        'parent_user_id' => (int) $mapping->coordinator_user_id,
                        'user_id' => (int) $mapping->sales_user_id,
                    ]),
            );
            $historicalSales = User::query()->with('role:id,slug')
                ->whereIn('id', $historicalMappings->pluck('user_id')->unique())
                ->where('account_status', AccountStatus::Active->value)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $this->isRole($user, 'sales'));
            $historicalByCoordinator = $historicalMappings
                ->groupBy('parent_user_id')
                ->map(fn (Collection $items) => $items->pluck('user_id')->map(fn ($id) => (int) $id)->reject(
                    fn (int $id) => $salesIdsByCoordinator->flatten()->contains($id),
                )->unique()->values());
            $sales = $sales->merge($historicalSales)->unique('id')->values();
            $historicalByCoordinator->each(function (Collection $historicalSalesIds, int|string $coordinatorId) use (&$salesIdsByCoordinator): void {
                $salesIdsByCoordinator->put(
                    $coordinatorId,
                    collect($salesIdsByCoordinator->get($coordinatorId, []))
                        ->merge($historicalSalesIds)
                        ->unique()
                        ->values(),
                );
            });
        }
        $coordinatorIdsBySupervisor = $coordinators->groupBy(fn (User $user) => $reportingAssignments->get($user->id)?->parent_user_id)
            ->map(fn (Collection $items) => $items->pluck('id')->map(fn ($id) => (int) $id)->values());

        return [
            'supervisors' => $supervisors,
            'coordinators' => $coordinators,
            'sales' => $sales,
            'sales_ids_by_coordinator' => $salesIdsByCoordinator,
            'coordinator_ids_by_supervisor' => $coordinatorIdsBySupervisor,
        ];
    }
}
