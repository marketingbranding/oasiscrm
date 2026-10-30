<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphService;
use App\Services\ReportingHierarchyService;
use App\Services\SalesTeamScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationGraphModeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_graph_legacy_rollback_restores_the_initial_effective_scope(): void
    {
        $branch = $this->branch('ROL');
        $supervisor = $this->user('supervisor', $branch);
        $child = $this->user('sales_coordinator', $branch);
        $child->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        config(['app.env' => 'local', 'organization.graph_user_ids' => (string) $supervisor->id, 'organization.graph_branch_ids' => '']);

        config(['organization.graph_mode' => 'legacy']);
        $legacy = app(ReportingHierarchyService::class)->descendantIds($supervisor);

        config(['organization.graph_mode' => 'graph']);
        $graph = app(ReportingHierarchyService::class)->descendantIds($supervisor);

        config(['organization.graph_mode' => 'legacy']);
        $rolledBack = app(ReportingHierarchyService::class)->descendantIds($supervisor);

        $this->assertSame([$child->id], $legacy);
        $this->assertSame([], $graph);
        $this->assertSame($legacy, $rolledBack);
    }

    public function test_graph_scope_does_not_grant_capability_and_preserves_independent_edges(): void
    {
        $branch = $this->branch('SEC');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $sales->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(OrganizationGraphService::class)->assign($sales, $supervisor);
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => (string) $coordinator->id,
            'organization.graph_branch_ids' => '',
        ]);

        $permissionBefore = $coordinator->hasPermission('users.update');
        $salesTeam = app(SalesTeamScopeService::class)->currentSales($coordinator)->pluck('id')->all();
        $permissionAfter = $coordinator->fresh()->hasPermission('users.update');

        $this->assertSame([], $salesTeam);
        $this->assertSame($permissionBefore, $permissionAfter);
        $this->assertFalse($permissionAfter);
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
}
