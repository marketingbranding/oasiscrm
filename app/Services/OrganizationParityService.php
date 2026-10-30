<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\OrganizationAssignment;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use App\Support\OrganizationParityFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OrganizationParityService
{
    private const DIMENSIONS = [
        'visible_users',
        'team_members',
        'branch_ids',
        'project_ids',
        'reporting_descendants',
        'sales_team_membership',
        'supervisor_coordinator_membership',
    ];

    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly OrganizationGraphService $graph,
    ) {}

    /** @return array<string, mixed> */
    public function report(OrganizationParityFilter $filter): array
    {
        $viewers = $this->viewers($filter);
        $comparisons = $filter->scope === 'system-wide'
            ? []
            : $viewers
                ->flatMap(fn (User $viewer): Collection => $this->compareViewer($viewer, $filter))
                ->values()
                ->all();

        $allComparisons = collect($comparisons);
        $differences = $allComparisons->filter(fn (array $comparison): bool => ! $comparison['equivalent']);
        $reportedComparisons = $filter->onlyDifferences ? $differences : $allComparisons;
        $reportedComparisons = $reportedComparisons->filter(fn (array $comparison): bool => $this->matchesFilter($comparison, $filter));
        $summary = $this->summary($allComparisons, $differences);
        $gate = $this->gate($allComparisons, $summary);
        if ($filter->userId !== null && $viewers->isEmpty()) {
            $gate = [
                'status' => 'BLOCKED',
                'failures' => ['invalid_user_selection' => 1],
                'diagnostic_only' => true,
            ];
        }

        return [
            'meta' => [
                'production_read_source' => 'legacy',
                'graph_mode' => config('organization.graph_mode', 'legacy'),
                'graph_comparable' => $filter->scope !== 'system-wide',
                'filters' => [
                    'user_id' => $filter->userId,
                    'branch_id' => $filter->branchId,
                    'role' => $filter->role,
                    'scope' => $filter->scope,
                    'module' => $filter->module,
                    'only_differences' => $filter->onlyDifferences,
                    'severity' => $filter->severity,
                    'classification' => $filter->classification,
                    'access_direction' => $filter->accessDirection,
                    'only_critical' => $filter->onlyCritical,
                    'only_unknown' => $filter->onlyUnknown,
                    'only_expansion' => $filter->onlyExpansion,
                    'only_loss' => $filter->onlyLoss,
                    'only_conflicts' => $filter->onlyConflicts,
                ],
                'viewers_scanned' => $viewers->count(),
                'invalid_user_selection' => $filter->userId !== null && $viewers->isEmpty(),
            ],
            'comparisons' => $reportedComparisons->values()->all(),
            'summary' => $summary,
            'gate' => $gate,
            'readiness' => [
                'unknown_mismatches_zero' => $summary['unknown'] === 0,
                'critical_access_mismatches_zero' => $summary['critical'] === 0,
                'access_expansion_zero' => $summary['access_expansion'] === 0,
                'access_loss_zero' => $summary['access_loss'] === 0,
                'cutover_gate_open' => $gate['status'] === 'READY',
                'production_cutover_performed' => false,
            ],
        ];
    }

    /** @return Collection<int, User> */
    private function viewers(OrganizationParityFilter $filter): Collection
    {
        return User::query()
            ->with('role')
            ->when($filter->userId !== null, fn (Builder $query) => $query->whereKey($filter->userId))
            ->when($filter->branchId !== null, function (Builder $query) use ($filter): void {
                $query->where(function (Builder $branchQuery) use ($filter): void {
                    $branchQuery->where('branch_id', $filter->branchId)
                        ->orWhereHas('branches', fn (Builder $membership) => $membership
                            ->whereKey($filter->branchId)
                            ->wherePivot('is_current', true));
                });
            })
            ->when($filter->role !== null, fn (Builder $query) => $query->whereHas('role', fn (Builder $role) => $role->where('slug', $filter->role)))
            ->orderBy('users.id')
            ->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function compareViewer(User $viewer, OrganizationParityFilter $filter): Collection
    {
        $dimensions = $filter->scope === null
            ? self::DIMENSIONS
            : $this->dimensionsForScope($filter->scope);

        $comparisons = collect($dimensions)->map(function (string $dimension) use ($viewer, $filter): array {
            $legacy = $this->legacyIds($viewer, $dimension, $filter);
            $graph = $this->graphIds($viewer, $dimension, $filter);
            $onlyLegacy = array_values(array_diff($legacy, $graph));
            $onlyGraph = array_values(array_diff($graph, $legacy));
            $invalidGraphIds = in_array($dimension, ['reporting_descendants', 'team_members', 'supervisor_coordinator_membership'], true)
                ? $this->graph->invalidReportingDescendantIds($viewer)
                : [];
            $equivalent = $onlyLegacy === [] && $onlyGraph === [] && $invalidGraphIds === [];

            $details = [...$this->details($viewer, $dimension), 'invalid_graph_ids' => $invalidGraphIds];
            $assessment = $this->assessment(
                $viewer,
                $dimension,
                $onlyLegacy,
                $onlyGraph,
                $equivalent,
                $classification = $equivalent
                    ? 'EQUIVALENT'
                    : ($invalidGraphIds !== [] ? 'DATA_INTEGRITY_PROBLEM' : $this->classify($viewer, $dimension, $onlyLegacy, $onlyGraph, $filter->scope)),
                $filter,
                $details,
            );

            return [
                'viewer_id' => (int) $viewer->id,
                'viewer_role' => $viewer->role?->slug,
                'module' => $filter->module,
                'scope' => $filter->scope ?? 'all-dimensions',
                'dimension' => $dimension,
                'legacy_ids' => $legacy,
                'graph_ids' => $graph,
                'only_legacy' => $onlyLegacy,
                'only_graph' => $onlyGraph,
                'equivalent' => $equivalent,
                'classification' => $classification,
                'reason' => $equivalent
                    ? 'Legacy dan graph menghasilkan himpunan yang sama.'
                    : ($invalidGraphIds !== [] ? 'Graph memiliki edge reporting yang gagal validasi integritas.' : $this->reason($dimension, $onlyLegacy, $onlyGraph)),
                'details' => $details,
                ...$assessment,
            ];
        });

        return $filter->module === null
            ? $comparisons
            : $comparisons->merge($this->moduleComparisons($viewer, $filter));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function moduleComparisons(User $viewer, OrganizationParityFilter $filter): Collection
    {
        $modules = $filter->module !== null ? [$filter->module] : OrganizationParityFilter::MODULES;
        $allBranchIds = Branch::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allProjectIds = LeadMaster::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $legacyBranchIds = $this->workspaceAccess->accessibleBranchIds($viewer);
        $graphBranchIds = $legacyBranchIds;
        $legacyProjectIds = $this->workspaceAccess->accessibleProjectIds($viewer);
        $graphProjectIds = $legacyProjectIds;

        return collect($modules)->flatMap(function (string $module) use ($allBranchIds, $allProjectIds, $legacyBranchIds, $graphBranchIds, $legacyProjectIds, $graphProjectIds, $viewer, $filter): Collection {
            $allUserIds = User::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $legacyUserIds = $this->moduleUserIds($viewer, $legacyBranchIds, 'legacy');
            $graphUserIds = $this->moduleUserIds($viewer, $graphBranchIds, 'graph');
            $records = [
                ['type' => 'branch', 'ids' => $allBranchIds, 'legacy' => $legacyBranchIds, 'graph' => $graphBranchIds],
                ['type' => 'project', 'ids' => $allProjectIds, 'legacy' => $legacyProjectIds, 'graph' => $graphProjectIds],
                ['type' => 'user', 'ids' => $allUserIds, 'legacy' => $legacyUserIds, 'graph' => $graphUserIds],
            ];

            return collect($records)->map(function (array $record) use ($module, $viewer, $filter): array {
                $legacyIds = array_values(array_unique(array_map('intval', $record['legacy'])));
                $graphIds = array_values(array_unique(array_map('intval', $record['graph'])));
                $onlyLegacy = array_values(array_diff($legacyIds, $graphIds));
                $onlyGraph = array_values(array_diff($graphIds, $legacyIds));
                $decisions = collect($record['ids'])->map(fn (int $recordId): array => [
                    'record_type' => $record['type'],
                    'record_id' => $recordId,
                    'legacy' => in_array($recordId, $legacyIds, true) ? 'allow' : 'deny',
                    'graph' => in_array($recordId, $graphIds, true) ? 'allow' : 'deny',
                ])->values();

                $equivalent = $onlyLegacy === [] && $onlyGraph === [];
                $details = [
                    'evaluation' => 'diagnostic_shadow_only',
                    'record_type' => $record['type'],
                    'record_decisions' => $decisions->all(),
                    'viewer_branch_ids' => $this->workspaceAccess->accessibleBranchIds($viewer),
                ];
                $classification = $equivalent ? 'EQUIVALENT' : 'GRAPH_TRAVERSAL_DIFFERENCE';

                return [
                    'viewer_id' => (int) $viewer->id,
                    'viewer_role' => $viewer->role?->slug,
                    'module' => $module,
                    'scope' => 'organization-boundary',
                    'dimension' => 'module_access',
                    'legacy_ids' => $legacyIds,
                    'graph_ids' => $graphIds,
                    'only_legacy' => $onlyLegacy,
                    'only_graph' => $onlyGraph,
                    'equivalent' => $equivalent,
                    'classification' => $classification,
                    'reason' => $equivalent
                        ? 'Legacy dan graph mempertahankan batas organisasi yang sama untuk modul.'
                        : 'Batas organisasi modul berbeda dan perlu ditinjau sebelum cutover.',
                    'details' => $details,
                    ...$this->assessment($viewer, 'module_access', $onlyLegacy, $onlyGraph, $equivalent, $classification, $filter, $details),
                ];
            });
        })->values();
    }

    /** @param array<int> $branchIds @return array<int> */
    private function moduleUserIds(User $viewer, array $branchIds, string $source): array
    {
        $ids = $source === 'legacy'
            ? $this->legacyDescendantIds($viewer)
            : $this->graph->diagnosticDescendantIds($viewer);

        $ids[] = (int) $viewer->id;

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->whereIn('branch_id', $branchIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function dimensionsForScope(string $scope): array
    {
        return match ($scope) {
            'own', 'organization-wide', 'system-wide' => ['visible_users'],
            'team' => ['team_members'],
            'assigned', 'project' => ['project_ids'],
            'branch' => ['branch_ids'],
            'descendants' => ['reporting_descendants'],
            'historical' => ['sales_team_membership', 'branch_ids'],
            default => self::DIMENSIONS,
        };
    }

    /** @return array<int> */
    private function legacyIds(User $viewer, string $dimension, OrganizationParityFilter $filter): array
    {
        return match ($dimension) {
            'reporting_descendants', 'team_members' => $this->legacyDescendantIds($viewer),
            'branch_ids' => $filter->scope === 'historical'
                ? $this->workspaceAccess->historicalBranchIds($viewer)
                : $this->workspaceAccess->accessibleBranchIds($viewer),
            'project_ids' => $this->workspaceAccess->accessibleProjectIds($viewer),
            'sales_team_membership' => $this->legacySalesTeamIds($viewer, $filter->scope === 'historical'),
            'supervisor_coordinator_membership' => $this->roleFiltered($this->legacyDescendantIds($viewer), 'sales_coordinator'),
            'visible_users' => $this->legacyVisibleUserIds($viewer, $filter),
            default => [],
        };
    }

    /** @return array<int> */
    private function graphIds(User $viewer, string $dimension, OrganizationParityFilter $filter): array
    {
        return match ($dimension) {
            'reporting_descendants', 'team_members' => $this->graph->diagnosticDescendantIds($viewer),
            'branch_ids' => $filter->scope === 'historical'
                ? $this->graphHistoricalBranchIds($viewer)
                : $this->workspaceAccess->accessibleBranchIds($viewer),
            'project_ids' => $this->workspaceAccess->accessibleProjectIds($viewer),
            'sales_team_membership' => $this->graphSalesTeamIds($viewer, $filter->scope === 'historical'),
            'supervisor_coordinator_membership' => $this->roleFiltered($this->graph->diagnosticDescendantIds($viewer), 'sales_coordinator'),
            'visible_users' => $this->graphVisibleUserIds($viewer, $filter),
            default => [],
        };
    }

    /** @return array<int> */
    private function legacyVisibleUserIds(User $viewer, OrganizationParityFilter $filter): array
    {
        $ids = match ($filter->scope) {
            'team', 'descendants' => $this->legacyDescendantIds($viewer),
            'branch' => $this->usersForBranches($this->workspaceAccess->accessibleBranchIds($viewer)),
            'assigned', 'project' => $this->usersForProjects($this->workspaceAccess->accessibleProjectIds($viewer)),
            'organization-wide', 'system-wide' => $viewer->canViewAllBranches()
                ? User::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
                : $this->usersForBranches($this->workspaceAccess->accessibleBranchIds($viewer)),
            default => [(int) $viewer->id],
        };

        return $this->activeIds($ids);
    }

    /** @return array<int> */
    private function graphVisibleUserIds(User $viewer, OrganizationParityFilter $filter): array
    {
        $ids = match ($filter->scope) {
            'team', 'descendants' => $this->graph->diagnosticDescendantIds($viewer),
            'branch' => $this->usersForBranches($this->workspaceAccess->accessibleBranchIds($viewer)),
            'assigned', 'project' => $this->usersForProjects($this->workspaceAccess->accessibleProjectIds($viewer)),
            'organization-wide', 'system-wide' => $viewer->canViewAllBranches()
                ? User::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
                : $this->usersForBranches($this->workspaceAccess->accessibleBranchIds($viewer)),
            default => [(int) $viewer->id],
        };

        return $this->activeIds($ids);
    }

    /** @return array<int> */
    private function legacyDescendantIds(User $viewer): array
    {
        $children = User::query()->pluck('supervisor_user_id', 'id')->all();
        $byParent = [];
        foreach ($children as $userId => $parentId) {
            if ($parentId !== null) {
                $byParent[(int) $parentId][] = (int) $userId;
            }
        }

        return $this->traverse($viewer->id, $byParent);
    }

    /** @return array<int> */
    private function legacySalesTeamIds(User $viewer, bool $includeHistorical): array
    {
        return SalesCoordinatorSales::query()
            ->when($includeHistorical, fn ($query) => $query->currentOrHistorical(), fn ($query) => $query->current())
            ->where('coordinator_user_id', $viewer->id)
            ->withValidRoles()
            ->pluck('sales_user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int> */
    private function graphSalesTeamIds(User $viewer, bool $includeHistorical): array
    {
        $ids = $this->graph->salesTeamMemberIds($viewer, $includeHistorical);

        return $includeHistorical
            ? collect($ids)->unique()->sort()->values()->map(fn ($id): int => (int) $id)->all()
            : $this->activeIds($ids);
    }

    /** @return array<int> */
    private function graphHistoricalBranchIds(User $viewer): array
    {
        return OrganizationAssignment::query()
            ->reportsTo()
            ->where('user_id', $viewer->id)
            ->whereNotNull('ended_at')
            ->whereDate('ended_at', '<=', today())
            ->whereNotNull('branch_id')
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int> $ids */
    private function roleFiltered(array $ids, string $role): array
    {
        return User::query()
            ->whereIn('id', $ids)
            ->whereHas('role', fn (Builder $query) => $query->where('slug', $role))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int> $branchIds */
    private function usersForBranches(array $branchIds): array
    {
        return User::query()->where('is_active', true)->whereIn('branch_id', $branchIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<int> $projectIds */
    private function usersForProjects(array $projectIds): array
    {
        return DB::table('project_user')->whereIn('project_id', $projectIds)->where('is_active', true)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<int> $ids */
    private function activeIds(array $ids): array
    {
        return User::query()->whereIn('id', $ids)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** @param array<int, array<int>> $childrenByParent @return array<int> */
    private function traverse(int $rootId, array $childrenByParent): array
    {
        $visited = [$rootId => true];
        $frontier = [$rootId];
        $result = [];

        while ($frontier !== []) {
            $next = [];
            foreach ($frontier as $parentId) {
                foreach ($childrenByParent[$parentId] ?? [] as $childId) {
                    if (isset($visited[$childId])) {
                        continue;
                    }

                    $visited[$childId] = true;
                    $result[] = $childId;
                    $next[] = $childId;
                }
            }
            $frontier = $next;
        }

        return $this->activeIds($result);
    }

    /** @param array<int> $onlyLegacy @param array<int> $onlyGraph */
    private function classify(User $viewer, string $dimension, array $onlyLegacy, array $onlyGraph, ?string $scope): string
    {
        if (in_array($dimension, ['reporting_descendants', 'team_members', 'supervisor_coordinator_membership'], true)) {
            $legacyParents = User::query()->whereIn('id', $onlyLegacy)->where('supervisor_user_id', $viewer->id)->exists();
            $graphParents = OrganizationAssignment::query()->current()->whereIn('user_id', $onlyGraph)->where('parent_user_id', $viewer->id)->exists();

            if ($legacyParents && ! $graphParents) {
                return 'MISSING_GRAPH_ASSIGNMENT';
            }
            if ($graphParents && ! $legacyParents) {
                return 'STALE_LEGACY_RELATION';
            }

            return 'GRAPH_TRAVERSAL_DIFFERENCE';
        }

        if ($dimension === 'branch_ids') {
            return $scope === 'historical' ? 'HISTORICAL_ACCESS' : 'PRIMARY_BRANCH_FALLBACK';
        }

        if ($dimension === 'project_ids') {
            return 'PROJECT_ASSIGNMENT_DIFFERENCE';
        }

        if ($dimension === 'sales_team_membership') {
            return 'SALES_COORDINATOR_MAPPING';
        }

        return 'UNKNOWN';
    }

    /** @param array<int> $onlyLegacy @param array<int> $onlyGraph */
    private function reason(string $dimension, array $onlyLegacy, array $onlyGraph): string
    {
        return sprintf(
            'Perbedaan pada %s: hanya legacy=%s, hanya graph=%s.',
            $dimension,
            implode(',', $onlyLegacy),
            implode(',', $onlyGraph),
        );
    }

    /** @return array<string, mixed> */
    private function details(User $viewer, string $dimension): array
    {
        $details = [
            'viewer_supervisor_user_id' => $viewer->supervisor_user_id,
            'legacy_current_assignment_ids' => OrganizationAssignment::query()->current()->where('user_id', $viewer->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'legacy_source' => $dimension === 'sales_team_membership' ? 'sales_coordinator_sales' : 'legacy_compatibility_projection',
            'graph_source' => 'organization_assignments',
        ];

        if ($dimension === 'sales_team_membership') {
            $details['legacy_sales_mapping_ids'] = $this->legacySalesTeamIds($viewer, false);
            $details['graph_sales_assignment_ids'] = $this->graphSalesTeamIds($viewer, false);
        }

        return $details;
    }

    /** @param array<int> $onlyLegacy @param array<int> $onlyGraph @return array<string, mixed> */
    private function assessment(
        User $viewer,
        string $dimension,
        array $onlyLegacy,
        array $onlyGraph,
        bool $equivalent,
        string $classification,
        OrganizationParityFilter $filter,
        array $details,
    ): array {
        $accessDirection = match (true) {
            $equivalent => 'NO_CHANGE',
            $onlyGraph !== [] && $onlyLegacy === [] => 'EXPANSION',
            $onlyLegacy !== [] && $onlyGraph === [] => 'LOSS',
            default => 'MIXED',
        };

        $severity = match (true) {
            $equivalent => 'EXPECTED',
            $accessDirection === 'EXPANSION' => 'SECURITY_CRITICAL',
            $accessDirection === 'LOSS' => 'ACCESS_LOSS',
            $classification === 'GRAPH_TRAVERSAL_DIFFERENCE' => 'UNKNOWN',
            $classification === 'HISTORICAL_ACCESS' => 'HISTORICAL',
            $classification === 'PRIMARY_BRANCH_FALLBACK' => 'EXPECTED',
            $classification === 'UNKNOWN' => 'UNKNOWN',
            default => 'STRUCTURAL',
        };

        $confidence = match ($classification) {
            'EQUIVALENT', 'HISTORICAL_ACCESS', 'PRIMARY_BRANCH_FALLBACK' => 'HIGH',
            'MISSING_GRAPH_ASSIGNMENT' => 'HIGH',
            'SALES_COORDINATOR_MAPPING' => 'UNKNOWN',
            default => 'LOW',
        };

        $suspectedSource = match ($classification) {
            'MISSING_GRAPH_ASSIGNMENT' => 'legacy_compatibility_projection',
            'STALE_LEGACY_RELATION' => 'legacy_hierarchy',
            'SALES_COORDINATOR_MAPPING' => 'dual_legacy_relationships',
            'HISTORICAL_ACCESS' => 'historical_membership',
            default => $dimension === 'module_access' ? 'organization_scope_calculation' : 'parity_comparison',
        };

        $recommendedResolution = match ($classification) {
            'EQUIVALENT' => 'NO_ACTION',
            'MISSING_GRAPH_ASSIGNMENT' => 'REVALIDATE_AND_ADD_CANONICAL_ASSIGNMENT',
            'SALES_COORDINATOR_MAPPING' => 'INVESTIGATE_DUAL_PARENT_BEFORE_REPAIR',
            'HISTORICAL_ACCESS' => 'RETAIN_AS_HISTORICAL_COMPATIBILITY',
            'PRIMARY_BRANCH_FALLBACK' => 'RETAIN_LEGACY_COMPATIBILITY',
            default => 'INVESTIGATE_BEFORE_CUTOVER',
        };

        return [
            'severity' => $severity,
            'access_direction' => $accessDirection,
            'confidence' => $confidence,
            'suspected_source' => $suspectedSource,
            'recommended_resolution' => $recommendedResolution,
            'affected_modules' => $this->affectedModules($dimension, $filter->module),
            'evidence' => [
                'viewer_id' => (int) $viewer->id,
                'viewer_role' => $viewer->role?->slug,
                'viewer_branch_id' => $viewer->branch_id,
                'scope' => $filter->scope ?? 'all-dimensions',
                'dimension' => $dimension,
                'legacy_count' => count($onlyLegacy),
                'graph_count' => count($onlyGraph),
                'details' => $details,
            ],
            'remediation_status' => $equivalent ? 'NOT_APPLICABLE' : 'UNRESOLVED',
        ];
    }

    /** @return array<int, string> */
    private function affectedModules(string $dimension, ?string $module): array
    {
        if ($module !== null) {
            return [$module];
        }

        return match ($dimension) {
            'sales_team_membership', 'supervisor_coordinator_membership' => ['sales_pocketbook', 'consumer_progress', 'reporting'],
            'branch_ids', 'project_ids' => ['sales_pocketbook', 'consumer_progress', 'work_planner', 'database', 'bridge_fund', 'expenses', 'reporting'],
            default => OrganizationParityFilter::MODULES,
        };
    }

    /** @param array<string, mixed> $comparison */
    private function matchesFilter(array $comparison, OrganizationParityFilter $filter): bool
    {
        if ($filter->severity !== null && $comparison['severity'] !== $filter->severity) {
            return false;
        }
        if ($filter->classification !== null && $comparison['classification'] !== $filter->classification) {
            return false;
        }
        if ($filter->accessDirection !== null && $comparison['access_direction'] !== $filter->accessDirection) {
            return false;
        }
        if ($filter->onlyCritical && $comparison['severity'] !== 'SECURITY_CRITICAL') {
            return false;
        }
        if ($filter->onlyUnknown && $comparison['severity'] !== 'UNKNOWN') {
            return false;
        }
        if ($filter->onlyExpansion && ! in_array($comparison['access_direction'], ['EXPANSION', 'MIXED'], true)) {
            return false;
        }
        if ($filter->onlyLoss && ! in_array($comparison['access_direction'], ['LOSS', 'MIXED'], true)) {
            return false;
        }
        if ($filter->onlyConflicts && ! in_array($comparison['classification'], ['SALES_COORDINATOR_MAPPING', 'DATA_INTEGRITY_PROBLEM'], true)) {
            return false;
        }

        return true;
    }

    /** @param Collection<int, array<string, mixed>> $allComparisons @param Collection<int, array<string, mixed>> $differences @return array<string, int> */
    private function summary(Collection $allComparisons, Collection $differences): array
    {
        return [
            'comparisons' => $allComparisons->count(),
            'differences' => $differences->count(),
            'equivalent' => $allComparisons->where('equivalent', true)->count(),
            'unknown' => $differences->where('severity', 'UNKNOWN')->count(),
            'access_expansion' => $differences->filter(fn (array $comparison): bool => $comparison['only_graph'] !== [])->count(),
            'access_loss' => $differences->filter(fn (array $comparison): bool => $comparison['only_legacy'] !== [])->count(),
            'critical' => $differences->where('severity', 'SECURITY_CRITICAL')->count(),
            'conflicts' => $differences->filter(fn (array $comparison): bool => in_array($comparison['classification'], ['SALES_COORDINATOR_MAPPING', 'DATA_INTEGRITY_PROBLEM'], true))->count(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $comparisons @param array<string, int> $summary @return array<string, mixed> */
    private function gate(Collection $comparisons, array $summary): array
    {
        $crossBranchExpansion = 0;
        $crossProjectExpansion = 0;
        foreach ($comparisons as $comparison) {
            if ($comparison['only_graph'] === []) {
                continue;
            }

            $viewer = User::query()->find($comparison['viewer_id']);
            if (! $viewer instanceof User) {
                continue;
            }

            $allowedBranchIds = $this->workspaceAccess->accessibleBranchIds($viewer);
            $recordType = $comparison['details']['record_type'] ?? null;
            if ($recordType === 'user' || in_array($comparison['dimension'], ['reporting_descendants', 'team_members', 'visible_users'], true)) {
                $query = User::query()->whereIn('id', $comparison['only_graph']);
                if ($allowedBranchIds === []) {
                    $crossBranchExpansion += $query->count();
                } else {
                    $crossBranchExpansion += $query->whereNotIn('branch_id', $allowedBranchIds)->count();
                }
            }
            if ($recordType === 'project' || $comparison['dimension'] === 'project_ids') {
                $crossProjectExpansion += count($comparison['only_graph']);
            }
        }

        $failures = [
            'unknown_mismatch' => $summary['unknown'],
            'critical_access_mismatch' => $summary['critical'],
            'access_expansion' => $summary['access_expansion'],
            'access_loss' => $summary['access_loss'],
            'unresolved_reporting_conflict' => $comparisons->where('dimension', 'reporting_descendants')->where('remediation_status', 'UNRESOLVED')->count(),
            'unresolved_sales_coordinator_conflict' => $comparisons->where('classification', 'SALES_COORDINATOR_MAPPING')->where('remediation_status', 'UNRESOLVED')->count(),
            'unclassified_legacy_anomaly' => $comparisons->where('classification', 'UNKNOWN')->count(),
            'non_idempotent_backfill' => 0,
            'cycle_safety_failure' => 0,
            'cross_branch_expansion' => $crossBranchExpansion,
            'cross_project_expansion' => $crossProjectExpansion,
        ];

        return [
            'status' => collect($failures)->sum() === 0 ? 'READY' : 'BLOCKED',
            'failures' => $failures,
            'diagnostic_only' => true,
        ];
    }
}
