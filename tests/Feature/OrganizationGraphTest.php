<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Exceptions\OrganizationAssignmentConflictException;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\OrganizationAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use App\Policies\SalesLeadPolicy;
use App\Services\CoordinatorSalesMonitoringService;
use App\Services\OrganizationBackfillService;
use App\Services\OrganizationGraphService;
use App\Services\OrganizationScopeService;
use App\Services\ReportingHierarchyService;
use App\Services\SalesTeamScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrganizationGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_move_retains_history_and_updates_legacy_projection_without_changing_role(): void
    {
        $branch = $this->branch('MGL');
        $actor = $this->user('superadmin', $branch);
        $supervisor = $this->user('supervisor', $branch);
        $firstCoordinator = $this->user('sales_coordinator', $branch);
        $secondCoordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);

        $graph->assign($firstCoordinator, $supervisor);
        $graph->assign($secondCoordinator, $supervisor);
        $initial = $graph->assign($sales, $firstCoordinator);

        $graph->move($actor, $sales, $secondCoordinator, expectedAssignmentId: $initial->id, expectedVersion: 1);

        $this->assertSame('sales', $sales->fresh()->role->slug);
        $this->assertSame($secondCoordinator->id, $graph->currentParent($sales)?->id);
        $this->assertSame(2, $graph->historicalAssignments($sales)->count());
        $this->assertSame($firstCoordinator->id, $graph->historicalAssignments($sales)->last()->parent_user_id);
        $this->assertContains($sales->id, $graph->descendantIds($supervisor));
        $this->assertDatabaseHas('sales_coordinator_sales', [
            'coordinator_user_id' => $secondCoordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $sales->id,
            'event' => 'organization_assignment_changed',
        ]);
    }

    public function test_graph_rejects_cycles_self_parent_and_invalid_role_relationships(): void
    {
        $branch = $this->branch('SLO');
        $actor = $this->user('superadmin', $branch);
        $first = $this->user('supervisor', $branch);
        $second = $this->user('supervisor', $branch);
        $third = $this->user('supervisor', $branch);
        $graph = app(OrganizationGraphService::class);

        $graph->assign($second, $first);
        $graph->assign($third, $second);

        $this->expectException(ValidationException::class);
        $graph->move($actor, $first, $third);
    }

    public function test_graph_rejects_self_parent_and_unregistered_role_pair(): void
    {
        $branch = $this->branch('BDG');
        $actor = $this->user('superadmin', $branch);
        $sales = $this->user('sales', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $graph = app(OrganizationGraphService::class);

        try {
            $graph->move($actor, $sales, $sales);
            $this->fail('Self-parent assignment should fail.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ValidationException::class);
        $graph->move($actor, $coordinator, $sales);
    }

    public function test_graph_rejects_stale_moves_with_conflict_and_no_duplicate_active_assignment(): void
    {
        $branch = $this->branch('RVA');
        $actor = $this->user('superadmin', $branch);
        $first = $this->user('sales_coordinator', $branch);
        $second = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);
        $current = $graph->assign($sales, $first);

        $graph->move($actor, $sales, $second, expectedAssignmentId: $current->id, expectedVersion: 1);

        $this->expectException(OrganizationAssignmentConflictException::class);
        $graph->move($actor, $sales, $first, expectedAssignmentId: $current->id, expectedVersion: 1);
    }

    public function test_backfill_is_dry_run_then_idempotent_and_preserves_legacy_rows(): void
    {
        $branch = $this->branch('PLB');
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today(),
        ]);
        $service = app(OrganizationBackfillService::class);

        $dryRun = $service->run();
        $this->assertSame(1, $dryRun['assignments_proposed']);
        $this->assertSame(0, $dryRun['created']);
        $this->assertDatabaseCount('organization_assignments', 0);

        $result = $service->run(false);
        $this->assertSame(1, $result['created']);
        $this->assertDatabaseCount('organization_assignments', 1);
        $this->assertDatabaseCount('sales_coordinator_sales', 1);

        $again = $service->run(false);
        $this->assertSame(1, $again['already_migrated']);
        $this->assertSame(0, $again['created']);
        $this->assertDatabaseCount('organization_assignments', 1);
    }

    public function test_backfill_migrates_legacy_hierarchy_without_writes_in_dry_run_and_is_idempotent(): void
    {
        $branch = $this->branch('LGC');
        $supervisor = $this->user('supervisor', $branch);
        $coordinatorOne = $this->user('sales_coordinator', $branch);
        $coordinatorTwo = $this->user('sales_coordinator', $branch);
        $salesA = $this->user('sales', $branch);
        $salesB = $this->user('sales', $branch);
        $salesC = $this->user('sales', $branch);

        $coordinatorOne->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        $coordinatorTwo->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        $this->legacySalesMapping($coordinatorOne, $salesA);
        $this->legacySalesMapping($coordinatorOne, $salesB);
        $this->legacySalesMapping($coordinatorTwo, $salesC);

        $service = app(OrganizationBackfillService::class);
        $dryRun = $service->run();

        $this->assertSame(5, $dryRun['assignments_proposed']);
        $this->assertSame(0, $dryRun['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
        $this->assertDatabaseCount('sales_coordinator_sales', 3);

        $actual = $service->run(false);

        $this->assertSame(5, $actual['created']);
        $this->assertSame(5, $actual['assignments_proposed']);
        $this->assertSame(5, OrganizationAssignment::query()->current()->count());
        $this->assertSame($supervisor->id, app(OrganizationGraphService::class)->currentParent($coordinatorOne)?->id);
        $this->assertSame($supervisor->id, app(OrganizationGraphService::class)->currentParent($coordinatorTwo)?->id);
        $this->assertSame($coordinatorOne->id, app(OrganizationGraphService::class)->currentParent($salesA)?->id);
        $this->assertSame($coordinatorOne->id, app(OrganizationGraphService::class)->currentParent($salesB)?->id);
        $this->assertSame($coordinatorTwo->id, app(OrganizationGraphService::class)->currentParent($salesC)?->id);

        $again = $service->run(false);

        $this->assertSame(0, $again['assignments_proposed']);
        $this->assertSame(0, $again['created']);
        $this->assertSame(5, $again['already_migrated']);
        $this->assertSame(5, OrganizationAssignment::query()->current()->count());
        $this->assertDatabaseCount('sales_coordinator_sales', 3);
    }

    public function test_backfill_command_runs_dry_run_actual_and_second_pass_on_legacy_fixture(): void
    {
        $branch = $this->branch('CMD');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $coordinator->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        $this->legacySalesMapping($coordinator, $sales);

        $this->artisan('organization:backfill', ['--dry-run' => true])->assertExitCode(0);
        $this->assertDatabaseCount('organization_assignments', 0);

        $this->artisan('organization:backfill')->assertExitCode(0);
        $this->assertDatabaseCount('organization_assignments', 2);

        $this->artisan('organization:backfill')->assertExitCode(0);
        $this->assertDatabaseCount('organization_assignments', 2);
        $this->assertSame(2, OrganizationAssignment::query()->current()->count());
    }

    public function test_backfill_reports_coordinator_supervisor_ambiguity_without_mutation(): void
    {
        $branch = $this->branch('AMB');
        $coordinatorOne = $this->user('sales_coordinator', $branch);
        $coordinatorTwo = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $sales->forceFill(['supervisor_user_id' => $coordinatorTwo->id])->saveQuietly();
        $mapping = $this->legacySalesMapping($coordinatorOne, $sales);

        $report = app(OrganizationBackfillService::class)->run(false);

        $this->assertSame(1, $report['conflicts']);
        $this->assertSame('Coordinator Sales dan supervisor legacy berbeda.', $report['details'][0]['reason']);
        $this->assertSame(0, $report['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
        $this->assertDatabaseHas('sales_coordinator_sales', ['id' => $mapping->id, 'is_active' => true]);
        $this->assertSame($coordinatorTwo->id, $sales->fresh()->supervisor_user_id);
    }

    public function test_backfill_reports_multiple_active_coordinators_without_mutation(): void
    {
        $branch = $this->branch('MLT');
        $coordinatorOne = $this->user('sales_coordinator', $branch);
        $coordinatorTwo = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $first = $this->legacySalesMapping($coordinatorOne, $sales);
        $second = $this->legacySalesMapping($coordinatorTwo, $sales);

        $report = app(OrganizationBackfillService::class)->run(false);

        $this->assertSame(1, $report['multiple_active_coordinator_assignments']);
        $this->assertSame(0, $report['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
        $this->assertDatabaseHas('sales_coordinator_sales', ['id' => $first->id, 'is_active' => true]);
        $this->assertDatabaseHas('sales_coordinator_sales', ['id' => $second->id, 'is_active' => true]);
    }

    public function test_backfill_reports_legacy_cycle_without_partial_writes(): void
    {
        $branch = $this->branch('CYC');
        $first = $this->user('supervisor', $branch);
        $second = $this->user('supervisor', $branch);
        $first->forceFill(['supervisor_user_id' => $second->id])->saveQuietly();
        $second->forceFill(['supervisor_user_id' => $first->id])->saveQuietly();

        $report = app(OrganizationBackfillService::class)->run(false);

        $this->assertSame(2, $report['cycles']);
        $this->assertSame(0, $report['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
        $this->assertSame($second->id, $first->fresh()->supervisor_user_id);
        $this->assertSame($first->id, $second->fresh()->supervisor_user_id);
    }

    public function test_backfill_reports_inactive_parent_without_mutation(): void
    {
        $branch = $this->branch('INA');
        $parent = $this->user('supervisor', $branch);
        $child = $this->user('sales_coordinator', $branch);
        $parent->forceFill(['is_active' => false, 'account_status' => AccountStatus::Inactive])->saveQuietly();
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();

        $report = app(OrganizationBackfillService::class)->run(false);

        $this->assertSame(1, $report['inactive_parents']);
        $this->assertSame(0, $report['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
    }

    public function test_backfill_reports_missing_legacy_parent_without_mutation(): void
    {
        $branch = $this->branch('MIS');
        $child = $this->user('sales_coordinator', $branch);
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $child->forceFill(['supervisor_user_id' => 999999])->saveQuietly();

        $report = app(OrganizationBackfillService::class)->run(false);

        $this->assertSame(1, $report['missing_users']);
        $this->assertSame(0, $report['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
    }

    public function test_backfill_reports_invalid_legacy_reporting_role_without_mutation(): void
    {
        $branch = $this->branch('ROL');
        $parent = $this->user('sales', $branch);
        $child = $this->user('sales_coordinator', $branch);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();

        $report = app(OrganizationBackfillService::class)->run(false);

        $this->assertSame(1, $report['invalid_role_relationships']);
        $this->assertSame(0, $report['created']);
        $this->assertDatabaseCount('organization_assignments', 0);
    }

    public function test_supplemental_role_cannot_move_a_user_without_primary_permission(): void
    {
        $branch = $this->branch('KDI');
        $actor = $this->user('sales', $branch);
        $actor->roles()->attach(Role::query()->where('slug', 'pusat')->firstOrFail());
        $target = $this->user('sales', $branch);
        $parent = $this->user('sales_coordinator', $branch);

        $this->assertFalse(app(OrganizationGraphService::class)->canMove($actor, $target, $parent));
    }

    public function test_canonical_mode_routes_reporting_and_sales_team_reads_through_graph(): void
    {
        config(['organization.graph_mode' => 'canonical']);
        $branch = $this->branch('JPR');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $sales = $this->user('sales', $branch);
        $graph = app(OrganizationGraphService::class);

        $graph->assign($coordinator, $supervisor);
        $graph->assign($sales, $coordinator);

        $this->assertContains($sales->id, app(OrganizationScopeService::class)->teamIds($supervisor));
        $this->assertTrue(app(SalesTeamScopeService::class)->contains($coordinator, $sales));
    }

    public function test_canonical_hierarchy_scope_does_not_read_legacy_supervisor_chain(): void
    {
        config(['organization.graph_mode' => 'canonical']);
        $branch = $this->branch('TGR');
        $supervisor = $this->user('supervisor', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $graph = app(OrganizationGraphService::class);

        $graph->assign($coordinator, $supervisor);
        $coordinator->forceFill(['supervisor_user_id' => null])->saveQuietly();

        $this->assertContains($supervisor->id, app(OrganizationScopeService::class)->hierarchyIds($coordinator));
    }

    public function test_shadow_parity_has_zero_mismatch_for_parent_children_descendants_and_scopes(): void
    {
        $branch = $this->branch('PAR');
        $supervisor = $this->user('supervisor', $branch);
        $coordinatorOne = $this->user('sales_coordinator', $branch);
        $coordinatorTwo = $this->user('sales_coordinator', $branch);
        $salesA = $this->user('sales', $branch);
        $salesB = $this->user('sales', $branch);
        $salesC = $this->user('sales', $branch);
        $coordinatorOne->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        $coordinatorTwo->forceFill(['supervisor_user_id' => $supervisor->id])->saveQuietly();
        $this->legacySalesMapping($coordinatorOne, $salesA);
        $this->legacySalesMapping($coordinatorOne, $salesB);
        $this->legacySalesMapping($coordinatorTwo, $salesC);
        app(OrganizationBackfillService::class)->run(false);

        config(['organization.graph_mode' => 'legacy']);
        app()->forgetScopedInstances();
        $legacyHierarchy = app(ReportingHierarchyService::class);
        $legacyScope = app(OrganizationScopeService::class);
        $legacySalesTeam = app(SalesTeamScopeService::class);
        $legacyDirectChildren = User::query()->where('supervisor_user_id', $supervisor->id)->pluck('id')->sort()->values()->all();
        $legacyCoordinatorChildren = User::query()->where('supervisor_user_id', $coordinatorOne->id)->pluck('id')->sort()->values()->all();
        $legacyDescendants = collect($legacyHierarchy->descendantIds($supervisor))->sort()->values()->all();
        $legacyCoordinatorSales = $legacySalesTeam->currentSales($coordinatorOne)->pluck('id')->sort()->values()->all();
        $legacyTeamIds = collect($legacyScope->teamIds($supervisor))->sort()->values()->all();
        $legacyHierarchyIds = collect($legacyScope->hierarchyIds($coordinatorOne))->sort()->values()->all();

        config(['organization.graph_mode' => 'canonical']);
        app()->forgetScopedInstances();
        $canonicalScope = app(OrganizationScopeService::class);
        $canonicalSalesTeam = app(SalesTeamScopeService::class);
        $canonicalDirectChildren = app(OrganizationGraphService::class)->directChildren($supervisor)->pluck('id')->sort()->values()->all();
        $canonicalCoordinatorChildren = app(OrganizationGraphService::class)->directChildren($coordinatorOne)->pluck('id')->sort()->values()->all();
        $canonicalDescendants = collect(app(OrganizationGraphService::class)->descendantIds($supervisor))->sort()->values()->all();
        $canonicalCoordinatorSales = $canonicalSalesTeam->currentSales($coordinatorOne)->pluck('id')->sort()->values()->all();
        $canonicalTeamIds = collect($canonicalScope->teamIds($supervisor))->sort()->values()->all();
        $canonicalHierarchyIds = collect($canonicalScope->hierarchyIds($coordinatorOne))->sort()->values()->all();

        $this->assertSame($legacyDirectChildren, $canonicalDirectChildren);
        $this->assertSame($legacyCoordinatorChildren, $canonicalCoordinatorChildren);
        $this->assertSame($legacyDescendants, $canonicalDescendants);
        $this->assertSame($legacyCoordinatorSales, $canonicalCoordinatorSales);
        $this->assertSame($legacyTeamIds, $canonicalTeamIds);
        $this->assertSame($legacyHierarchyIds, $canonicalHierarchyIds);
    }

    public function test_sales_move_updates_team_lead_access_and_preserves_role_permissions_and_history(): void
    {
        $branch = $this->branch('MOV');
        $supervisor = $this->user('supervisor', $branch);
        $coordinatorOne = $this->user('sales_coordinator', $branch);
        $coordinatorTwo = $this->user('sales_coordinator', $branch);
        $salesA = $this->user('sales', $branch);
        $salesB = $this->user('sales', $branch);
        $salesC = $this->user('sales', $branch);
        $salesD = $this->user('sales', $branch);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Move Project', 'is_active' => true]);
        foreach ([$salesA, $salesB, $salesC, $salesD] as $sales) {
            $sales->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true]]);
            $sales->assignedProjects()->syncWithoutDetaching([$project->id => ['is_active' => true, 'is_primary' => true]]);
        }
        $coordinatorOne->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true]]);
        $coordinatorTwo->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true]]);

        $graph = app(OrganizationGraphService::class);
        $graph->assign($coordinatorOne, $supervisor);
        $graph->assign($coordinatorTwo, $supervisor);
        $initial = $graph->assign($salesA, $coordinatorOne);
        $graph->assign($salesB, $coordinatorOne);
        $graph->assign($salesC, $coordinatorOne);
        $graph->assign($salesD, $coordinatorTwo);
        $leadA = SalesLead::create(['branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $salesA->id, 'lead_date' => today(), 'customer_name' => 'Sales A', 'created_by' => $salesA->id]);
        $leadB = SalesLead::create(['branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $salesB->id, 'lead_date' => today(), 'customer_name' => 'Sales B', 'created_by' => $salesB->id]);
        $agendaA = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'visibility' => 'team', 'title' => 'Agenda Sales A', 'scheduled_date' => today(), 'status' => 'planned', 'owner_user_id' => $salesA->id, 'created_by' => $salesA->id]);
        $agendaB = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'visibility' => 'team', 'title' => 'Agenda Sales B', 'scheduled_date' => today(), 'status' => 'planned', 'owner_user_id' => $salesB->id, 'created_by' => $salesB->id]);
        $consumerTeamPermissions = Permission::query()->whereIn('slug', ['consumer_progress.view', 'consumer_progress.view_team'])->pluck('id', 'slug');
        $this->assertCount(2, $consumerTeamPermissions);
        $coordinatorOne->role->permissions()->syncWithoutDetaching($consumerTeamPermissions->values()->all());
        $coordinatorTwo->role->permissions()->syncWithoutDetaching($consumerTeamPermissions->values()->all());
        $this->assertDatabaseHas('role_permission', ['role_id' => $coordinatorOne->role_id, 'permission_id' => $consumerTeamPermissions['consumer_progress.view_team']]);
        $coordinatorOne->unsetRelation('role');
        $coordinatorTwo->unsetRelation('role');
        $roleId = $salesA->role_id;
        $permissionSlugs = $salesA->role->permissions()->pluck('slug')->sort()->values()->all();

        $graph->move($this->user('superadmin', $branch), $salesA, $coordinatorTwo, expectedAssignmentId: $initial->id, expectedVersion: 1);

        config(['organization.graph_mode' => 'legacy']);
        app()->forgetScopedInstances();
        $legacyTeams = app(SalesTeamScopeService::class);
        $legacyScope = app(OrganizationScopeService::class);
        $this->assertSame([$salesB->id, $salesC->id], $legacyTeams->currentSales($coordinatorOne)->pluck('id')->sort()->values()->all());
        $this->assertSame([$salesA->id, $salesD->id], $legacyTeams->currentSales($coordinatorTwo)->pluck('id')->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$coordinatorOne->id, $coordinatorTwo->id, $salesA->id, $salesB->id, $salesC->id, $salesD->id], $legacyScope->teamIds($supervisor));

        config(['organization.graph_mode' => 'canonical']);
        app()->forgetScopedInstances();
        $canonicalTeams = app(SalesTeamScopeService::class);
        $canonicalScope = app(OrganizationScopeService::class);
        $this->assertSame([$salesB->id, $salesC->id], $canonicalTeams->currentSales($coordinatorOne)->pluck('id')->sort()->values()->all());
        $this->assertSame([$salesA->id, $salesD->id], $canonicalTeams->currentSales($coordinatorTwo)->pluck('id')->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$coordinatorOne->id, $coordinatorTwo->id, $salesA->id, $salesB->id, $salesC->id, $salesD->id], $canonicalScope->teamIds($supervisor));
        $this->assertTrue(app(SalesLeadPolicy::class)->update($coordinatorOne, $leadB));
        $this->assertFalse(app(SalesLeadPolicy::class)->update($coordinatorOne, $leadA));
        $this->assertTrue(app(SalesLeadPolicy::class)->update($coordinatorTwo, $leadA));
        $agendaScope = app(CoordinatorSalesMonitoringService::class);
        $this->assertTrue($agendaScope->canViewAgenda($coordinatorOne, $agendaB));
        $this->assertFalse($agendaScope->canViewAgenda($coordinatorOne, $agendaA));
        $this->assertTrue($agendaScope->canViewAgenda($coordinatorTwo, $agendaA));
        $this->assertTrue($coordinatorOne->hasPermission('consumer_progress.view_team'));
        $this->assertContains($salesB->id, $canonicalScope->teamIds($coordinatorOne));
        $this->assertEqualsCanonicalizing([$salesB->id, $salesC->id], app(OrganizationScopeService::class)->visibleUserIds($coordinatorOne, 'consumer_progress'));
        $this->assertEqualsCanonicalizing([$salesA->id, $salesD->id], app(OrganizationScopeService::class)->visibleUserIds($coordinatorTwo, 'consumer_progress'));

        $this->assertSame($roleId, $salesA->fresh()->role_id);
        $this->assertSame($permissionSlugs, $salesA->fresh()->role->permissions()->pluck('slug')->sort()->values()->all());
        $this->assertSame(1, OrganizationAssignment::query()->current()->where('user_id', $salesA->id)->count());
        $this->assertSame(2, OrganizationAssignment::query()->where('user_id', $salesA->id)->count());
        $this->assertDatabaseHas('organization_assignments', ['id' => $initial->id, 'is_active' => false]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $salesA->id, 'event' => 'organization_assignment_changed']);
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'is_active' => true,
            'account_status' => AccountStatus::Active,
        ]);
    }

    private function legacySalesMapping(User $coordinator, User $sales): SalesCoordinatorSales
    {
        return SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today(),
        ]);
    }
}
