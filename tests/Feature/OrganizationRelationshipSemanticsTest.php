<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphService;
use App\Services\SalesCoordinatorAssignmentService;
use App\Services\SalesTeamScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrganizationRelationshipSemanticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_and_sales_team_edges_coexist_and_traverse_separately(): void
    {
        $branch = $this->branch('SEM');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);

        $reporting = $graph->assign($sales, $supervisor);
        $salesTeam = $graph->assignSalesTeamMember($sales, $coordinator);

        $this->assertSame(OrganizationAssignment::REPORTS_TO, $reporting->relationship_type);
        $this->assertSame(OrganizationAssignment::SALES_TEAM_MEMBER_OF, $salesTeam->relationship_type);
        $this->assertSame($supervisor->id, $graph->currentParent($sales)?->id);
        $this->assertSame($coordinator->id, $graph->currentSalesTeamCoordinator($sales)?->id);
        $this->assertSame([$sales->id], $graph->salesTeamMemberIds($coordinator));
        $this->assertContains($sales->id, $graph->descendantIds($supervisor));
        $this->assertNotContains($sales->id, $graph->descendantIds($coordinator));
        $this->assertSame(1, OrganizationAssignment::query()->current()->where('user_id', $sales->id)->count());
        $this->assertSame(1, OrganizationAssignment::query()->currentSalesTeam()->where('user_id', $sales->id)->count());
    }

    public function test_reporting_structure_removal_does_not_end_independent_sales_team_edge(): void
    {
        $branch = $this->branch('REM');
        $actor = $this->user('superadmin', $branch);
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);
        $reporting = $graph->assign($sales, $supervisor);
        $graph->assignSalesTeamMember($sales, $coordinator);

        $graph->removeFromStructure($actor, $sales, $sales->organization_unit_id, $reporting->id, $reporting->lock_version);

        $this->assertNull($graph->currentParent($sales->fresh()));
        $this->assertSame($coordinator->id, $graph->currentSalesTeamCoordinator($sales->fresh())?->id);
        $this->assertSame(0, OrganizationAssignment::query()->current()->where('user_id', $sales->id)->count());
        $this->assertSame(1, OrganizationAssignment::query()->currentSalesTeam()->where('user_id', $sales->id)->count());
    }

    public function test_sales_team_edge_requires_active_sales_and_coordinator_roles_in_same_branch(): void
    {
        $branch = $this->branch('VAL');
        $otherBranch = $this->branch('VAX');
        $sales = $this->user('sales', $branch);
        $staff = $this->user('staff', $branch);
        $foreignCoordinator = $this->user('sales_coordinator', $otherBranch);
        $inactiveCoordinator = $this->user('sales_coordinator', $branch);
        $inactiveCoordinator->forceFill([
            'account_status' => AccountStatus::Inactive,
            'is_active' => false,
        ])->saveQuietly();
        $graph = app(OrganizationGraphService::class);

        try {
            $graph->assignSalesTeamMember($sales, $staff);
            $this->fail('A non-Coordinator target must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $graph->assignSalesTeamMember($sales, $foreignCoordinator);
            $this->fail('A cross-branch Coordinator must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ValidationException::class);
        $graph->assignSalesTeamMember($sales, $inactiveCoordinator);
    }

    public function test_compatibility_writer_dual_writes_and_changes_only_sales_team_parent(): void
    {
        $branch = $this->branch('DWS');
        $supervisor = $this->user('supervisor', $branch);
        $firstCoordinator = $this->user('sales_coordinator', $branch);
        $secondCoordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);
        $graph->assign($sales, $supervisor);
        $assignments = app(SalesCoordinatorAssignmentService::class);

        $assignments->assign($sales, $firstCoordinator);
        $assignments->assign($sales, $secondCoordinator);

        $this->assertSame($supervisor->id, $graph->currentParent($sales->fresh())?->id);
        $this->assertSame($secondCoordinator->id, $graph->currentSalesTeamCoordinator($sales->fresh())?->id);
        $this->assertDatabaseHas('sales_coordinator_sales', [
            'coordinator_user_id' => $secondCoordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
        ]);
        $this->assertSame(1, OrganizationAssignment::query()->currentSalesTeam()->where('user_id', $sales->id)->count());
        $this->assertSame(2, OrganizationAssignment::query()->salesTeamMemberOf()->where('user_id', $sales->id)->count());
    }

    public function test_canonical_sales_team_scope_reads_typed_edge_without_reporting_parent(): void
    {
        config(['organization.graph_mode' => 'canonical']);
        $branch = $this->branch('CAN');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);

        app(OrganizationGraphService::class)->assignSalesTeamMember($sales, $coordinator);

        $this->assertTrue(app(SalesTeamScopeService::class)->contains($coordinator, $sales));
        $this->assertSame([$sales->id], app(SalesTeamScopeService::class)->currentSales($coordinator)->pluck('id')->all());
    }

    public function test_legacy_sales_team_scope_does_not_read_typed_edge_without_legacy_mapping(): void
    {
        config(['organization.graph_mode' => 'legacy']);
        $branch = $this->branch('LEG');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);

        app(OrganizationGraphService::class)->assignSalesTeamMember($sales, $coordinator);

        $this->assertFalse(app(SalesTeamScopeService::class)->contains($coordinator, $sales));
        $this->assertSame([], app(SalesTeamScopeService::class)->currentSales($coordinator)->pluck('id')->all());
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
