<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphService;
use App\Services\OrganizationScopeService;
use App\Services\SalesCoordinatorAssignmentService;
use App\Services\SalesTeamScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_reporting_to_a_coordinator_does_not_create_sales_team_membership(): void
    {
        $branch = $this->branch('RCO');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);

        $graph->assign($sales, $coordinator);

        $this->assertDatabaseCount('sales_coordinator_sales', 0);
        $this->assertDatabaseMissing('organization_assignments', [
            'user_id' => $sales->id,
            'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
        ]);
        $this->assertSame($coordinator->id, $graph->currentParent($sales)?->id);
        $this->assertNull($graph->currentSalesTeamCoordinator($sales));
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

    public function test_only_coordinator_and_only_supervisor_relationships_have_separate_access_effects(): void
    {
        config(['organization.graph_mode' => 'canonical']);
        $branch = $this->branch('SEP');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $coordinatorOnlySales = $this->user('sales', $branch);
        $supervisorOnlySales = $this->user('sales', $branch);
        $unassignedSales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);
        $salesTeam = app(SalesTeamScopeService::class);
        $scope = app(OrganizationScopeService::class);

        $graph->assignSalesTeamMember($coordinatorOnlySales, $coordinator);
        $graph->assign($supervisorOnlySales, $supervisor);

        $this->assertTrue($salesTeam->contains($coordinator, $coordinatorOnlySales));
        $this->assertNotContains($coordinatorOnlySales->id, $graph->descendantIds($coordinator));
        $this->assertContains($supervisorOnlySales->id, $graph->descendantIds($supervisor));
        $this->assertFalse($salesTeam->contains($coordinator, $supervisorOnlySales));
        $this->assertSame([], $scope->teamIds($unassignedSales));
        $this->assertFalse($coordinatorOnlySales->fresh()->hasPermission('consumer_progress.manage'));
        $this->assertFalse($coordinatorOnlySales->fresh()->hasPermission('expenses.manage'));
    }

    public function test_changing_one_relationship_does_not_move_the_other_or_grant_historical_access(): void
    {
        config(['organization.graph_mode' => 'canonical']);
        $branch = $this->branch('MOV');
        $firstSupervisor = $this->user('supervisor', $branch);
        $secondSupervisor = $this->user('supervisor', $branch);
        $firstCoordinator = $this->user('sales_coordinator', $branch);
        $secondCoordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);

        $reporting = $graph->assign($sales, $firstSupervisor);
        $graph->assignSalesTeamMember($sales, $firstCoordinator);
        $graph->move($this->user('superadmin', $branch), $sales, $secondSupervisor, expectedAssignmentId: $reporting->id, expectedVersion: 1);

        $this->assertSame($secondSupervisor->id, $graph->currentParent($sales->fresh())?->id);
        $this->assertSame($firstCoordinator->id, $graph->currentSalesTeamCoordinator($sales->fresh())?->id);
        $this->assertContains($sales->id, $graph->salesTeamMemberIds($firstCoordinator, includeHistorical: true));
        $this->assertContains($sales->id, $graph->salesTeamMemberIds($firstCoordinator));

        $graph->assignSalesTeamMember($sales, $secondCoordinator);

        $this->assertSame($secondSupervisor->id, $graph->currentParent($sales->fresh())?->id);
        $this->assertSame($secondCoordinator->id, $graph->currentSalesTeamCoordinator($sales->fresh())?->id);
        $this->assertContains($sales->id, $graph->salesTeamMemberIds($firstCoordinator, includeHistorical: true));
        $this->assertNotContains($sales->id, $graph->salesTeamMemberIds($firstCoordinator));
        $this->assertContains($sales->id, $graph->salesTeamMemberIds($secondCoordinator));
        $this->assertSame(2, $graph->historicalAssignments($sales->fresh())->count());
    }

    public function test_ambiguous_current_sales_team_edges_fail_closed_in_canonical_reads_and_writes(): void
    {
        config(['organization.graph_mode' => 'canonical']);
        $branch = $this->branch('AMB');
        $firstCoordinator = $this->user('sales_coordinator', $branch);
        $secondCoordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);

        foreach ([$firstCoordinator, $secondCoordinator] as $coordinator) {
            OrganizationAssignment::query()->create([
                'user_id' => $sales->id,
                'parent_user_id' => $coordinator->id,
                'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
                'branch_id' => $branch->id,
                'started_at' => today()->toDateString(),
                'is_active' => true,
                'is_primary' => true,
                'source' => 'ambiguous_fixture',
                'lock_version' => 1,
            ]);
        }

        $this->assertNull(app(OrganizationGraphService::class)->currentSalesTeamCoordinator($sales));
        $this->assertSame([], app(SalesTeamScopeService::class)->currentSales($firstCoordinator)->pluck('id')->all());
        $this->expectException(ValidationException::class);
        app(OrganizationGraphService::class)->assignSalesTeamMember($sales, $firstCoordinator);
    }

    public function test_historical_legacy_sales_mapping_is_available_only_in_historical_scope(): void
    {
        config(['organization.graph_mode' => 'canonical']);
        $branch = $this->branch('HST');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today()->subDays(5)->toDateString(),
            'ended_at' => today()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = app(SalesTeamScopeService::class);

        $this->assertSame([], $scope->currentSales($coordinator)->pluck('id')->all());
        $this->assertContains($sales->id, $scope->for($coordinator, includeHistorical: true)['sales']->pluck('id')->all());
    }

    public function test_sales_team_replacement_audit_records_old_and_new_coordinators(): void
    {
        $branch = $this->branch('AUD');
        $firstCoordinator = $this->user('sales_coordinator', $branch);
        $secondCoordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);

        $graph->assignSalesTeamMember($sales, $firstCoordinator);
        $graph->assignSalesTeamMember($sales, $secondCoordinator);

        $log = ActivityLog::query()
            ->where('subject_id', $sales->id)
            ->where('event', 'organization_sales_team_assignment_changed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($firstCoordinator->id, $log->properties['old']['coordinator_id']);
        $this->assertSame($secondCoordinator->id, $log->properties['new']['coordinator_id']);
    }

    public function test_stale_typed_sales_team_edges_fail_closed_after_branch_or_role_changes(): void
    {
        config(['organization.graph_mode' => 'canonical']);
        $branch = $this->branch('STL');
        $otherBranch = $this->branch('STX');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);
        $graph->assignSalesTeamMember($sales, $coordinator);

        $sales->forceFill(['branch_id' => $otherBranch->id])->saveQuietly();
        $this->assertFalse(app(SalesTeamScopeService::class)->contains($coordinator, $sales));

        $sales->forceFill(['branch_id' => $branch->id, 'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->id])->saveQuietly();
        $this->assertFalse(app(SalesTeamScopeService::class)->contains($coordinator, $sales));
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
