<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphService;
use App\Services\OrganizationGraphShadowService;
use App\Services\OrganizationScopeService;
use App\Services\ReportingHierarchyService;
use App\Services\SalesTeamScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationGraphModeReadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_shadow_returns_legacy_values_and_records_canonical_mismatch(): void
    {
        $branch = $this->branch('RSH');
        $supervisor = $this->user('supervisor', $branch);
        $child = $this->user('sales_coordinator', $branch);
        $child->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        config(['app.env' => 'local', 'organization.graph_mode' => 'shadow', 'organization.graph_user_ids' => '', 'organization.graph_branch_ids' => '']);
        $shadow = app(OrganizationGraphShadowService::class);
        $shadow->reset();

        $this->assertSame([$child->id], app(ReportingHierarchyService::class)->descendantIds($supervisor));
        $this->assertSame(1, $shadow->snapshot()['reporting_descendants.mismatch']);
    }

    public function test_reporting_graph_requires_context_and_returns_canonical_values_when_activated(): void
    {
        $branch = $this->branch('RGR');
        $supervisor = $this->user('supervisor', $branch);
        $child = $this->user('sales_coordinator', $branch);
        $child->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => (string) $supervisor->id,
            'organization.graph_branch_ids' => '',
        ]);

        $this->assertSame([], app(ReportingHierarchyService::class)->descendantIds($supervisor));

        config(['organization.graph_user_ids' => '']);
        $this->assertSame([$child->id], app(ReportingHierarchyService::class)->descendantIds($supervisor));
    }

    public function test_sales_team_shadow_preserves_legacy_membership_and_graph_mode_does_not_union_edges(): void
    {
        $branch = $this->branch('SSH');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $this->legacyMapping($coordinator, $sales);
        config(['app.env' => 'local', 'organization.graph_mode' => 'shadow', 'organization.graph_user_ids' => '', 'organization.graph_branch_ids' => '']);
        $shadow = app(OrganizationGraphShadowService::class);
        $shadow->reset();

        $legacyTeam = app(SalesTeamScopeService::class)->for($coordinator);
        $this->assertSame([$sales->id], $legacyTeam['sales']->pluck('id')->all());
        $this->assertSame(1, $shadow->snapshot()['sales_team_scope.mismatch']);

        config([
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => (string) $coordinator->id,
        ]);
        $graphTeam = app(SalesTeamScopeService::class)->for($coordinator);
        $this->assertSame([], $graphTeam['sales']->pluck('id')->all());
    }

    public function test_organization_hierarchy_graph_mode_uses_canonical_ancestors_without_fallback_to_legacy(): void
    {
        $branch = $this->branch('HGR');
        $parent = $this->user('supervisor', $branch);
        $viewer = $this->user('staff', $branch);
        $viewer->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => (string) $viewer->id,
            'organization.graph_branch_ids' => '',
        ]);

        $this->assertSame([], app(OrganizationScopeService::class)->hierarchyIds($viewer));
    }

    public function test_branch_activation_does_not_cross_activate_another_read_context(): void
    {
        $allowedBranch = $this->branch('BAL');
        $otherBranch = $this->branch('BOT');
        $supervisor = $this->user('supervisor', $allowedBranch);
        $child = $this->user('sales_coordinator', $allowedBranch);
        $child->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => '',
            'organization.graph_branch_ids' => (string) $allowedBranch->id,
        ]);

        $this->assertSame([$child->id], app(ReportingHierarchyService::class)->descendantIds($supervisor, 100, $otherBranch->id));
        $this->assertSame([], app(ReportingHierarchyService::class)->descendantIds($supervisor, 100, $allowedBranch->id));
    }

    public function test_graph_reads_exclude_active_edges_that_fail_role_or_branch_integrity(): void
    {
        $branch = $this->branch('GIN');
        $invalidParent = $this->user('sales', $branch);
        $child = $this->user('manager', $branch);
        OrganizationAssignment::query()->create([
            'user_id' => $child->id,
            'parent_user_id' => $invalidParent->id,
            'relationship_type' => OrganizationAssignment::REPORTS_TO,
            'branch_id' => null,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'integrity_fixture',
            'lock_version' => 1,
        ]);
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => (string) $invalidParent->id,
            'organization.graph_branch_ids' => '',
        ]);

        $this->assertSame([], app(ReportingHierarchyService::class)->descendantIds($invalidParent));
    }

    public function test_legacy_hierarchy_does_not_execute_canonical_graph_traversal(): void
    {
        $branch = $this->branch('LEG');
        $parent = $this->user('supervisor', $branch);
        $viewer = $this->user('staff', $branch);
        $viewer->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        config(['app.env' => 'local', 'organization.graph_mode' => 'legacy']);

        $graph = \Mockery::mock(OrganizationGraphService::class);
        $graph->shouldReceive('ancestors')->never();
        $graph->shouldReceive('descendantIds')->never();
        app()->instance(OrganizationGraphService::class, $graph);

        $hierarchyIds = app(OrganizationScopeService::class)->hierarchyIds($viewer);

        $this->assertContains($parent->id, $hierarchyIds);
    }

    public function test_legacy_mode_skips_canonical_traversal_in_reporting_and_sales_reads(): void
    {
        $branch = $this->branch('LGC');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $coordinator->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        $this->legacyMapping($coordinator, $sales);
        config(['app.env' => 'local', 'organization.graph_mode' => 'legacy']);

        $graph = \Mockery::mock(OrganizationGraphService::class);
        $graph->shouldReceive('ancestors')->never();
        $graph->shouldReceive('descendantIds')->never();
        $graph->shouldReceive('descendants')->never();
        $graph->shouldReceive('salesTeamMemberIds')->never();
        app()->instance(OrganizationGraphService::class, $graph);

        $this->assertSame([$coordinator->id], app(ReportingHierarchyService::class)->descendantIds($supervisor));
        $this->assertSame([$sales->id], app(SalesTeamScopeService::class)->for($coordinator)['sales']->pluck('id')->all());
    }

    public function test_shadow_graph_failure_preserves_legacy_reporting_result(): void
    {
        $branch = $this->branch('SFL');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('sales_coordinator', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        config(['app.env' => 'local', 'organization.graph_mode' => 'shadow']);
        $shadow = app(OrganizationGraphShadowService::class);
        $shadow->reset();

        $graph = \Mockery::mock(OrganizationGraphService::class);
        $graph->shouldReceive('descendantIds')->once()->andThrow(new \RuntimeException('graph unavailable'));
        app()->instance(OrganizationGraphService::class, $graph);

        $descendants = app(ReportingHierarchyService::class)->descendantIds($parent);

        $this->assertSame([$child->id], $descendants);
        $this->assertSame(1, $shadow->snapshot()['reporting_descendants.failure']);
    }

    public function test_graph_integrity_failure_falls_back_to_legacy_reporting_result(): void
    {
        $branch = $this->branch('GFL');
        $parent = $this->user('sales', $branch);
        $child = $this->user('manager', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        OrganizationAssignment::query()->create([
            'user_id' => $child->id,
            'parent_user_id' => $parent->id,
            'relationship_type' => OrganizationAssignment::REPORTS_TO,
            'branch_id' => null,
            'started_at' => today()->toDateString(),
            'is_active' => true,
            'is_primary' => true,
            'source' => 'integrity_fixture',
            'lock_version' => 1,
        ]);
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => (string) $parent->id,
            'organization.graph_branch_ids' => '',
        ]);

        $this->assertSame([$child->id], app(ReportingHierarchyService::class)->descendantIds($parent));
    }

    public function test_shadow_sales_team_failure_preserves_legacy_result(): void
    {
        $branch = $this->branch('SSL');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $this->legacyMapping($coordinator, $sales);
        config(['app.env' => 'local', 'organization.graph_mode' => 'shadow']);
        $shadow = app(OrganizationGraphShadowService::class);
        $shadow->reset();

        $graph = \Mockery::mock(OrganizationGraphService::class);
        $graph->shouldReceive('descendants')->once()->andThrow(new \RuntimeException('graph unavailable'));
        app()->instance(OrganizationGraphService::class, $graph);

        $team = app(SalesTeamScopeService::class)->for($coordinator);

        $this->assertSame([$sales->id], $team['sales']->pluck('id')->all());
        $this->assertSame(1, $shadow->snapshot()['sales_team_scope.failure']);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function user(string $roleSlug, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'branch_id' => $branch->id,
        ]);
    }

    private function legacyMapping(User $coordinator, User $sales): void
    {
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
