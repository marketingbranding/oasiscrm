<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphService;
use App\Services\OrganizationParityService;
use App\Support\OrganizationParityFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class OrganizationParityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_scope_report_has_stable_machine_readable_contract(): void
    {
        $viewer = $this->user('staff', $this->branch('SLO'));

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, scope: 'descendants'),
        );

        $this->assertArrayHasKey('meta', $report);
        $this->assertArrayHasKey('comparisons', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('readiness', $report);
        $this->assertNotEmpty($report['comparisons']);

        $comparison = $report['comparisons'][0];
        foreach ([
            'viewer_id',
            'viewer_role',
            'module',
            'scope',
            'dimension',
            'legacy_ids',
            'graph_ids',
            'only_legacy',
            'only_graph',
            'equivalent',
            'classification',
            'reason',
            'details',
            'severity',
            'access_direction',
            'confidence',
            'suspected_source',
            'recommended_resolution',
            'affected_modules',
            'evidence',
            'remediation_status',
        ] as $key) {
            $this->assertArrayHasKey($key, $comparison, $key);
        }
    }

    public function test_legacy_supervisor_without_graph_assignment_is_classified_as_missing_graph_assignment(): void
    {
        $branch = $this->branch('SLO');
        $viewer = $this->user('supervisor', $branch);
        $direct = $this->user('sales_coordinator', $branch);
        $direct->forceFill(['supervisor_user_id' => $viewer->id])->saveQuietly();

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, scope: 'descendants', onlyDifferences: true),
        );

        $comparison = collect($report['comparisons'])->firstWhere('dimension', 'reporting_descendants');

        $this->assertNotNull($comparison);
        $this->assertSame([$direct->id], $comparison['only_legacy']);
        $this->assertSame([], $comparison['only_graph']);
        $this->assertFalse($comparison['equivalent']);
        $this->assertSame('MISSING_GRAPH_ASSIGNMENT', $comparison['classification']);
    }

    public function test_legacy_comparison_does_not_change_when_production_graph_mode_is_canonical(): void
    {
        $branch = $this->branch('SLO');
        $viewer = $this->user('supervisor', $branch);
        $direct = $this->user('sales_coordinator', $branch);
        $direct->forceFill(['supervisor_user_id' => $viewer->id])->saveQuietly();
        $originalMode = config('organization.graph_mode');
        config(['organization.graph_mode' => 'canonical']);

        try {
            $report = app(OrganizationParityService::class)->report(
                new OrganizationParityFilter(userId: $viewer->id, scope: 'descendants', onlyDifferences: true),
            );
        } finally {
            config(['organization.graph_mode' => $originalMode]);
        }

        $comparison = collect($report['comparisons'])->firstWhere('dimension', 'reporting_descendants');

        $this->assertNotNull($comparison);
        $this->assertSame([$direct->id], $comparison['only_legacy']);
        $this->assertSame('canonical', $report['meta']['graph_mode']);
    }

    public function test_filter_rejects_unknown_scope_and_unknown_role_does_not_broaden_results(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OrganizationParityFilter(scope: 'not-a-scope');
    }

    public function test_system_wide_scope_is_explicitly_excluded_from_graph_parity(): void
    {
        $viewer = $this->user('superadmin', $this->branch('SYS'));
        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, scope: 'system-wide'),
        );

        $this->assertFalse($report['meta']['graph_comparable']);
        $this->assertSame([], $report['comparisons']);
    }

    public function test_unknown_role_filter_returns_no_viewers(): void
    {
        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(role: 'not-a-role'),
        );

        $this->assertSame(0, $report['meta']['viewers_scanned']);
        $this->assertSame([], $report['comparisons']);
    }

    public function test_all_seeded_primary_roles_are_supported_by_the_parity_report(): void
    {
        $branch = $this->branch('ROL');
        $roles = [
            'sales',
            'sales_coordinator',
            'supervisor',
            'manager',
            'branch_manager',
            'sas',
            'sam',
            'pusat',
            'admin',
            'staff',
            'superadmin',
        ];

        foreach ($roles as $role) {
            $viewer = $this->user($role, $branch);
            $report = app(OrganizationParityService::class)->report(
                new OrganizationParityFilter(userId: $viewer->id, scope: 'own'),
            );

            $this->assertSame($role, $report['comparisons'][0]['viewer_role'], $role);
        }
    }

    public function test_primary_branch_fallback_and_current_project_assignment_are_visible_as_workspace_dimensions(): void
    {
        $primary = $this->branch('PRI');
        $additional = $this->branch('ADD');
        $project = LeadMaster::query()->create([
            'branch_id' => $additional->id,
            'project_name' => 'Proyek Tambahan',
            'is_active' => true,
        ]);
        $viewer = $this->user('manager', $primary);
        DB::table('branch_user')->where('user_id', $viewer->id)->delete();
        $viewer->branches()->attach($additional->id, [
            'is_current' => true,
            'can_view' => true,
            'can_edit' => true,
            'can_sync' => false,
            'can_manage_members' => false,
        ]);
        DB::table('project_user')->insert([
            'user_id' => $viewer->id,
            'project_id' => $project->id,
            'is_primary' => true,
            'is_active' => true,
            'assignment_start_date' => today()->toDateString(),
            'assignment_end_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id),
        );
        $byDimension = collect($report['comparisons'])->keyBy('dimension');

        $this->assertContains($primary->id, $byDimension['branch_ids']['legacy_ids']);
        $this->assertContains($additional->id, $byDimension['branch_ids']['legacy_ids']);
        $this->assertContains($project->id, $byDimension['project_ids']['legacy_ids']);
        $this->assertSame($byDimension['branch_ids']['legacy_ids'], $byDimension['branch_ids']['graph_ids']);
    }

    public function test_sales_coordinator_mapping_and_graph_parent_are_compared_without_normalizing_conflicts(): void
    {
        $branch = $this->branch('SAL');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->toDateString(),
            'ended_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $coordinator->id),
        );
        $comparison = collect($report['comparisons'])->firstWhere('dimension', 'sales_team_membership');

        $this->assertNotNull($comparison);
        $this->assertSame([$sales->id], $comparison['only_legacy']);
        $this->assertSame('SALES_COORDINATOR_MAPPING', $comparison['classification']);
        $this->assertSame([$sales->id], $comparison['details']['legacy_sales_mapping_ids']);
    }

    public function test_reporting_and_sales_team_parity_compare_independent_typed_edges(): void
    {
        $branch = $this->branch('ORTH');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $sales->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->toDateString(),
            'ended_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        OrganizationAssignment::query()->create([
            'user_id' => $sales->id,
            'parent_user_id' => $coordinator->id,
            'relationship_type' => OrganizationGraphService::SALES_TEAM_MEMBER_OF,
            'branch_id' => $branch->id,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'parity_fixture',
            'lock_version' => 1,
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $coordinator->id),
        );
        $salesTeam = collect($report['comparisons'])->firstWhere('dimension', 'sales_team_membership');

        $this->assertNotNull($salesTeam);
        $this->assertTrue($salesTeam['equivalent']);
        $this->assertSame([], $salesTeam['only_legacy']);
        $this->assertSame([], $salesTeam['only_graph']);
        $this->assertNotContains('SALES_COORDINATOR_MAPPING', collect($report['comparisons'])->pluck('classification')->all());
    }

    public function test_supplemental_role_does_not_change_organization_branch_comparison(): void
    {
        $branch = $this->branch('SUP');
        $viewer = $this->user('supervisor', $branch);
        $baseline = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, scope: 'branch'),
        );
        $viewer->roles()->attach(Role::query()->where('slug', 'pusat')->firstOrFail());
        $withSupplementalRole = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, scope: 'branch'),
        );

        $this->assertSame($baseline['comparisons'][0]['legacy_ids'], $withSupplementalRole['comparisons'][0]['legacy_ids']);
        $this->assertSame($baseline['comparisons'][0]['graph_ids'], $withSupplementalRole['comparisons'][0]['graph_ids']);
    }

    public function test_historical_branch_access_is_compared_as_historical_compatibility(): void
    {
        $currentBranch = $this->branch('HIS');
        $historicalBranch = $this->branch('OLD');
        $viewer = $this->user('manager', $currentBranch);
        $viewer->branches()->attach($historicalBranch->id, [
            'is_current' => false,
            'can_view' => true,
            'can_edit' => false,
            'can_sync' => false,
            'can_manage_members' => false,
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, scope: 'historical', onlyDifferences: true),
        );
        $comparison = collect($report['comparisons'])->firstWhere('dimension', 'branch_ids');

        $this->assertNotNull($comparison);
        $this->assertContains($historicalBranch->id, $comparison['only_legacy']);
        $this->assertSame('HISTORICAL_ACCESS', $comparison['classification']);
    }

    public function test_graph_allow_legacy_deny_is_security_critical_expansion_and_blocks_the_gate(): void
    {
        $branch = $this->branch('EXP');
        $viewer = $this->user('manager', $branch);
        $graphOnly = $this->user('staff', $branch);
        OrganizationAssignment::query()->create([
            'user_id' => $graphOnly->id,
            'parent_user_id' => $viewer->id,
            'organization_unit_id' => $viewer->organization_unit_id,
            'relationship_type' => OrganizationGraphService::REPORTS_TO,
            'branch_id' => $branch->id,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'parity_fixture',
            'lock_version' => 1,
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, module: 'work_planner', onlyDifferences: true),
        );
        $comparison = collect($report['comparisons'])
            ->where('dimension', 'module_access')
            ->first(fn (array $item): bool => $item['details']['record_type'] === 'user');

        $this->assertNotNull($comparison);
        $this->assertContains($graphOnly->id, $comparison['only_graph']);
        $this->assertSame('EXPANSION', $comparison['access_direction']);
        $this->assertSame('SECURITY_CRITICAL', $comparison['severity']);
        $this->assertSame('BLOCKED', $report['gate']['status']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['access_expansion']);
    }

    public function test_legacy_allow_graph_deny_is_access_loss_and_blocks_the_gate(): void
    {
        $branch = $this->branch('LOS');
        $viewer = $this->user('manager', $branch);
        $legacyOnly = $this->user('staff', $branch);
        $legacyOnly->forceFill(['supervisor_user_id' => $viewer->id])->saveQuietly();

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, module: 'work_planner', onlyDifferences: true),
        );
        $comparison = collect($report['comparisons'])
            ->where('dimension', 'module_access')
            ->first(fn (array $item): bool => $item['details']['record_type'] === 'user');

        $this->assertNotNull($comparison);
        $this->assertContains($legacyOnly->id, $comparison['only_legacy']);
        $this->assertSame('LOSS', $comparison['access_direction']);
        $this->assertSame('ACCESS_LOSS', $comparison['severity']);
        $this->assertSame('BLOCKED', $report['gate']['status']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['access_loss']);
    }

    public function test_graph_cycle_and_duplicate_edges_terminate_safely(): void
    {
        $branch = $this->branch('CYC');
        $first = $this->user('supervisor', $branch);
        $second = $this->user('sales_coordinator', $branch);
        $payload = fn (int $userId, int $parentId): array => [
            'user_id' => $userId,
            'parent_user_id' => $parentId,
            'organization_unit_id' => $first->organization_unit_id,
            'relationship_type' => OrganizationGraphService::REPORTS_TO,
            'branch_id' => $branch->id,
            'started_at' => today()->toDateString(),
            'ended_at' => null,
            'is_active' => true,
            'is_primary' => true,
            'source' => 'parity_fixture',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        OrganizationAssignment::query()->create($payload($first->id, $second->id));
        OrganizationAssignment::query()->create($payload($second->id, $first->id));
        OrganizationAssignment::query()->create($payload($second->id, $first->id));

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $first->id, scope: 'descendants'),
        );

        $this->assertCount(1, $report['comparisons']);
        $this->assertContains($second->id, $report['comparisons'][0]['graph_ids']);
    }

    public function test_mixed_legacy_and_graph_relationship_sets_remain_unknown_and_block_cutover(): void
    {
        $branch = $this->branch('UNK');
        $viewer = $this->user('supervisor', $branch);
        $legacyChild = $this->user('sales_coordinator', $branch);
        $graphChild = $this->user('sales_coordinator', $branch);
        $legacyChild->forceFill(['supervisor_user_id' => $viewer->id])->saveQuietly();
        OrganizationAssignment::query()->create([
            'user_id' => $graphChild->id,
            'parent_user_id' => $viewer->id,
            'organization_unit_id' => $viewer->organization_unit_id,
            'relationship_type' => OrganizationGraphService::REPORTS_TO,
            'branch_id' => $branch->id,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'parity_fixture',
            'lock_version' => 1,
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, scope: 'descendants', onlyDifferences: true),
        );
        $comparison = $report['comparisons'][0];

        $this->assertSame('GRAPH_TRAVERSAL_DIFFERENCE', $comparison['classification']);
        $this->assertSame('UNKNOWN', $comparison['severity']);
        $this->assertSame('MIXED', $comparison['access_direction']);
        $this->assertSame('BLOCKED', $report['gate']['status']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['unknown']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['access_expansion']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['access_loss']);
        $this->assertContains($legacyChild->id, $comparison['only_legacy']);
        $this->assertContains($graphChild->id, $comparison['only_graph']);
    }

    public function test_critical_filter_returns_only_access_expansion_differences(): void
    {
        $branch = $this->branch('FIL');
        $viewer = $this->user('manager', $branch);
        $graphOnly = $this->user('staff', $branch);
        OrganizationAssignment::query()->create([
            'user_id' => $graphOnly->id,
            'parent_user_id' => $viewer->id,
            'organization_unit_id' => $viewer->organization_unit_id,
            'relationship_type' => OrganizationGraphService::REPORTS_TO,
            'branch_id' => $branch->id,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'parity_fixture',
            'lock_version' => 1,
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(
                userId: $viewer->id,
                module: 'work_planner',
                onlyDifferences: true,
                onlyCritical: true,
            ),
        );

        $this->assertNotEmpty($report['comparisons']);
        $this->assertTrue(collect($report['comparisons'])->every(fn (array $comparison): bool => $comparison['severity'] === 'SECURITY_CRITICAL'));
        $this->assertTrue(collect($report['comparisons'])->every(fn (array $comparison): bool => $comparison['access_direction'] === 'EXPANSION'));
    }

    public function test_graph_cross_branch_expansion_is_counted_as_a_cutover_blocker(): void
    {
        $branch = $this->branch('CBL');
        $foreignBranch = $this->branch('CBF');
        $viewer = $this->user('manager', $branch);
        $foreignChild = $this->user('staff', $foreignBranch);
        OrganizationAssignment::query()->create([
            'user_id' => $foreignChild->id,
            'parent_user_id' => $viewer->id,
            'organization_unit_id' => $viewer->organization_unit_id,
            'relationship_type' => OrganizationGraphService::REPORTS_TO,
            'branch_id' => $foreignBranch->id,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'parity_fixture',
            'lock_version' => 1,
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, scope: 'descendants', onlyDifferences: true),
        );

        $this->assertGreaterThanOrEqual(1, $report['gate']['failures']['cross_branch_expansion']);
        $this->assertSame('BLOCKED', $report['gate']['status']);
    }

    public function test_module_shadow_reports_allow_deny_without_cross_branch_or_project_leakage(): void
    {
        $branch = $this->branch('MOD');
        $foreignBranch = $this->branch('OUT');
        $project = LeadMaster::query()->create([
            'branch_id' => $branch->id,
            'project_name' => 'Proyek Lokal',
            'is_active' => true,
        ]);
        $foreignProject = LeadMaster::query()->create([
            'branch_id' => $foreignBranch->id,
            'project_name' => 'Proyek Asing',
            'is_active' => true,
        ]);
        $viewer = $this->user('manager', $branch);
        $localUser = $this->user('staff', $branch);
        $foreignUser = $this->user('staff', $foreignBranch);
        OrganizationAssignment::query()->create([
            'user_id' => $localUser->id,
            'parent_user_id' => $viewer->id,
            'organization_unit_id' => $viewer->organization_unit_id,
            'relationship_type' => OrganizationGraphService::REPORTS_TO,
            'branch_id' => $branch->id,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'parity_fixture',
            'lock_version' => 1,
        ]);
        OrganizationAssignment::query()->create([
            'user_id' => $foreignUser->id,
            'parent_user_id' => $viewer->id,
            'organization_unit_id' => $viewer->organization_unit_id,
            'relationship_type' => OrganizationGraphService::REPORTS_TO,
            'branch_id' => $foreignBranch->id,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'parity_fixture',
            'lock_version' => 1,
        ]);

        $report = app(OrganizationParityService::class)->report(
            new OrganizationParityFilter(userId: $viewer->id, module: 'work_planner'),
        );
        $moduleComparisons = collect($report['comparisons'])->where('dimension', 'module_access')->values();

        $this->assertNotEmpty($moduleComparisons);
        $decisions = $moduleComparisons->flatMap(fn (array $comparison): array => $comparison['details']['record_decisions']);
        $branchDecisions = $decisions->where('record_type', 'branch');
        $projectDecisions = $decisions->where('record_type', 'project');
        $this->assertSame('allow', $branchDecisions->firstWhere('record_id', $branch->id)['legacy']);
        $this->assertSame('deny', $branchDecisions->firstWhere('record_id', $foreignBranch->id)['graph']);
        $this->assertSame('allow', $projectDecisions->firstWhere('record_id', $project->id)['legacy']);
        $this->assertSame('deny', $projectDecisions->firstWhere('record_id', $foreignProject->id)['graph']);
        $userDecisions = $decisions->where('record_type', 'user');
        $this->assertSame('deny', $userDecisions->firstWhere('record_id', $localUser->id)['legacy']);
        $this->assertSame('allow', $userDecisions->firstWhere('record_id', $localUser->id)['graph']);
        $this->assertFalse($report['readiness']['critical_access_mismatches_zero']);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->create([
            'name' => $code,
            'code' => $code,
            'is_active' => true,
        ]);
    }

    private function user(string $roleSlug, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'branch_id' => $branch->id,
        ]);
    }
}
