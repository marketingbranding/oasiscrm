<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
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
